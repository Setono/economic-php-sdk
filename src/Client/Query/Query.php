<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Query;

use Webmozart\Assert\Assert;

final class Query implements \Stringable
{
    public function __construct(private readonly array $parameters = [])
    {
    }

    public static function fromCollectionParameters(
        int $skipPages = 0,
        int $pageSize = 20,
        string $filter = null,
        string $sortBy = null,
    ): self {
        Assert::greaterThanEq($skipPages, 0);
        Assert::greaterThanEq($pageSize, 1);

        return new self([
            'skippages' => $skipPages,
            'pagesize' => $pageSize,
            'filter' => $filter,
            'sort' => $sortBy,
        ]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->parameters;
    }

    public function toString(): string
    {
        return http_build_query(array_map(static function (mixed $element) {
            return $element instanceof \DateTimeInterface ? $element->format(\DATE_ATOM) : $element;
        }, $this->parameters), '', '&', \PHP_QUERY_RFC3986);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
