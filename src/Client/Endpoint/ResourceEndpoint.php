<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Source\Source;
use Setono\Economic\Exception\MappingException;
use Setono\Economic\Request\Payload;
use Setono\Economic\Response\Resource;

/**
 * Abstract base for endpoints that represent a single REST resource at a path with a typed item DTO.
 *
 * Subclasses declare two protected hints: `getPath()` (the resource URL path) and `getItemClass()`
 * (the typed DTO class). Two shared helpers, symmetric in shape:
 *  - {@see self::getOne()}    — GET + decode + map + `$raw` stamp.
 *  - {@see self::createOne()} — POST typed body + decode + map + `$raw` stamp.
 *
 * Three known subclass shapes today:
 *  - {@see CollectionEndpoint} — adds pagination + `getItem(int|string $id)` for by-id lookups.
 *  - {@see SelfEndpoint}       — fetches the single resource at `/self` (no id), with memoization.
 *  - Leaf write-capable endpoints (e.g. `DraftOrdersEndpoint`, `CustomersEndpoint`) expose a typed
 *    public `create()` method that one-line-delegates to {@see self::createOne()}.
 *
 * @template T of Resource
 */
abstract class ResourceEndpoint extends Endpoint
{
    /**
     * Resource path for this endpoint — e.g. `"products"`, `"orders/drafts"`, `"self"`.
     *
     * @internal Implemented by SDK-internal endpoint subclasses to declare their resource path.
     *           Not part of the package's BC promise — the SDK may change the abstract signature
     *           between minor versions. Downstream subclassing is not supported.
     */
    abstract protected static function getPath(): string;

    /**
     * FQCN of the typed item DTO for this resource.
     *
     * @internal Implemented by SDK-internal endpoint subclasses. Not part of the package's BC
     *           promise — see {@see self::getPath()} for the rationale.
     *
     * @return class-string<T>
     */
    abstract protected static function getItemClass(): string;

    /**
     * Fetch the resource, map it via Valinor, and stamp `$raw` with the full decoded body.
     *
     * @internal Shared pipeline used by SDK-internal subclasses ({@see CollectionEndpoint::getItem()},
     *           {@see SelfEndpoint::get()}). Not part of the package's BC promise.
     *
     * @param int|string|null $id when null, fetches `getPath()`; when given, fetches `"{getPath()}/{$id}"`.
     *
     * @return T
     */
    protected function getOne(int|string|null $id = null): Resource
    {
        $path = null === $id
            ? static::getPath()
            : sprintf('%s/%s', static::getPath(), $id);

        $data = $this->client->get($path);

        // $raw is stamped by the polymorphic Resource converter registered in
        // Client::getMapperBuilder() — fires for every array→Resource mapping during this call,
        // including nested mappings inside Collection<X>.
        // NOTE: pass `$data` directly, not `Source::array($data)`. The Source wrapper makes the
        // shell value the Source object (not the underlying array), so our registered converter's
        // `array` first-parameter type doesn't accept it and the converter silently skips.
        /** @var T $item */
        $item = $this->mapResource(static::getItemClass(), $data);

        return $item;
    }

    /**
     * POST a typed request DTO to this endpoint's path and map the response into the typed item.
     * Symmetric to {@see self::getOne()}: same Valinor map pipeline, same `$raw` stamping. The
     * 404-to-null shortcut used by {@see CollectionEndpoint::getItem()} does NOT apply here —
     * any non-2xx response propagates as the appropriate typed exception.
     *
     * @internal Shared pipeline used by SDK-internal leaf write endpoints. Not part of the
     *           package's BC promise.
     *
     * @return T
     */
    protected function createOne(Payload $request): Resource
    {
        $data = $this->client->post(static::getPath(), $request);

        /** @var T $item */
        $item = $this->mapResource(static::getItemClass(), $data);

        return $item;
    }

    /**
     * Run a Valinor mapping call and convert `MappingError` (a 2xx response whose body
     * decoded as JSON but didn't fit the target DTO) into the SDK's typed
     * {@see MappingException}, preserving the original as `$previous` and embedding HTTP
     * method/URL context for debugging.
     *
     * Subclasses use this in their public methods via the `@var T` pattern at the call site
     * (see {@see self::getOne()} / {@see self::createOne()} / {@see CollectionEndpoint::getPage()}).
     *
     * @internal Shared SDK-internal helper. Not part of the BC promise.
     *
     * @param array<string, mixed> $data
     */
    protected function mapResource(string $signature, array $data): Resource
    {
        try {
            // Use the FQN here — ECS's PhpdocTypesFixer lowercases the unqualified `Resource`
            // to `resource` (the PHP primitive type) because the names collide.
            /** @var \Setono\Economic\Response\Resource $item */
            $item = $this->mapperBuilder->mapper()->map($signature, $data);

            return $item;
        } catch (MappingError $e) {
            $response = $this->client->lastResponse;
            $request = $this->client->lastRequest;

            if (null === $response) {
                // Defensive — shouldn't happen because mapping is called after a Client::get/post
                // dispatch that journals both. Surface the original error if so.
                throw $e;
            }

            $context = null === $request
                ? ''
                : sprintf(' [%s %s]', $request->getMethod(), (string) $request->getUri()->withQuery('')->withFragment(''));

            throw new MappingException(
                $response,
                sprintf(
                    'Could not map response body to %s%s: %s',
                    $signature,
                    $context,
                    $e->getMessage(),
                ),
                $e,
                request: $request,
            );
        }
    }
}
