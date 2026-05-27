<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use CuyZ\Valinor\Mapper\Source\Source;
use Setono\Economic\Response\Resource;

/**
 * Abstract base for endpoints that represent a single REST resource at a path with a typed item DTO.
 *
 * Subclasses declare two protected hints: `getPath()` (the resource URL path) and `getItemClass()`
 * (the typed DTO class). The shared `getOne()` helper does the GET + decode + map + `$raw` stamp.
 *
 * Two known subclass shapes today:
 *  - {@see CollectionEndpoint} — adds pagination + `getItem(int|string $id)` for by-id lookups.
 *  - {@see SelfEndpoint}       — fetches the single resource at `/self` (no id), with memoization.
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
        $item = $this->mapperBuilder->mapper()->map(
            static::getItemClass(),
            $data,
        );

        return $item;
    }
}
