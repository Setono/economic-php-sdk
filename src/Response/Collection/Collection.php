<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Collection;

use Setono\Economic\Response\Pagination\Pagination;
use Setono\Economic\Response\Resource;

/**
 * Passive data carrier for a single page of a paginated resource.
 *
 * Pagination logic does NOT live here — see `CollectionEndpoint::paginate()` for the walker.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Collection extends Resource implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $collection
     */
    public function __construct(
        public readonly array $collection,
        public readonly Pagination $pagination,
    ) {
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->collection);
    }

    public function count(): int
    {
        return count($this->collection);
    }

    public function isEmpty(): bool
    {
        return [] === $this->collection;
    }
}
