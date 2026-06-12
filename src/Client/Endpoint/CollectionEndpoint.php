<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Resource;

/**
 * Abstract base for every leaf endpoint that returns a paginated `Collection<T>`.
 *
 * Inherits `getPath()`, `getItemClass()`, and `getOne()` from {@see ResourceEndpoint}.
 * Adds `getItem()` (by-id lookup with null-on-404), `getPage()`, and `paginate()` on top.
 *
 * @template T of Resource
 * @extends ResourceEndpoint<T>
 */
abstract class CollectionEndpoint extends ResourceEndpoint
{
    /**
     * Fetch a single item from this collection by its identifier. Returns `null` on 404; other
     * HTTP errors propagate via the exception hierarchy.
     *
     * @internal Implementation helper for leaf sub-endpoints' `getByNumber(...)` methods.
     *           Not covered by the package's backwards-compatibility promise — the signature
     *           or behavior may change between minor versions. Consumers should call the
     *           leaf's typed `getByNumber()` rather than reaching for `getItem()` via subclass.
     *
     * @return T|null
     */
    protected function getItem(int|string $id): ?Resource
    {
        try {
            return $this->getOne($id);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Fetch a single page of the collection.
     *
     * @return Collection<T>
     */
    public function getPage(?CollectionRequestOptions $opts = null): Collection
    {
        $opts ??= new CollectionRequestOptions();

        return $this->mapPage($this->client->get(static::getPath(), $opts->toArray()));
    }

    /**
     * Walk all pages by following the server-provided `pagination.nextPage.url`.
     * Yields every item across all pages, in server order.
     *
     * **Non-transactional contract.** If an exception is thrown while fetching page N+1
     * (any `EconomicException` or `Psr\Http\Client\ClientExceptionInterface`), items from
     * page N have already been yielded and the generator is exhausted. The consumer cannot
     * resume from where it left off — re-invoking `paginate()` restarts at page 1 and may
     * yield duplicates. For reliable recovery, track processed identifiers externally
     * (de-dupe by `getByNumber`-style id) or wrap your PSR-18 client with retry middleware
     * so transient failures don't propagate. The SDK does NOT bake retry into the walker.
     *
     * @return \Generator<T>
     */
    public function paginate(?CollectionRequestOptions $opts = null): \Generator
    {
        $page = $this->getPage($opts);

        while (true) {
            yield from $page->collection;

            $nextUrl = $page->pagination->nextPage?->url;
            if (null === $nextUrl) {
                return;
            }

            $page = $this->mapPage($this->client->get($nextUrl));
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return Collection<T>
     */
    private function mapPage(array $data): Collection
    {
        $signature = sprintf(
            'Setono\Economic\Response\Collection\Collection<%s>',
            static::getItemClass(),
        );

        // `mapResource` (inherited) stamps $raw — on the Collection envelope AND on every item
        // inside it — and wraps Valinor's `MappingError` into a SDK `MappingException` with
        // HTTP context preserved.
        /** @var Collection<T> $page */
        $page = $this->mapResource($signature, $data);

        return $page;
    }
}
