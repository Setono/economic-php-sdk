<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Collection;

use Setono\Economic\Response\Pagination\Pagination;

/**
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Collection implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $collection
     */
    public function __construct(public readonly array $collection, public readonly Pagination $pagination)
    {
    }

    /**
     * @return T[]
     * @psalm-return \ArrayIterator<int<0, max>, T>
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
