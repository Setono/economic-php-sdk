<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use Webmozart\Assert\Assert;

final class CollectionRequestOptions
{
    public function __construct(
        public readonly int $skipPages = 0,
        public readonly int $pageSize = 20,
        public readonly ?string $filter = null,
        public readonly ?string $sortBy = null,
    ) {
        Assert::greaterThanEq($skipPages, 0);
        Assert::greaterThanEq($pageSize, 1);
    }

    public static function new(): self
    {
        return new self();
    }

    public function withSkipPages(int $skipPages): self
    {
        return new self($skipPages, $this->pageSize, $this->filter, $this->sortBy);
    }

    public function withPageSize(int $pageSize): self
    {
        return new self($this->skipPages, $pageSize, $this->filter, $this->sortBy);
    }

    public function withFilter(string $filter): self
    {
        return new self($this->skipPages, $this->pageSize, $filter, $this->sortBy);
    }

    public function withSortBy(string $sortBy): self
    {
        return new self($this->skipPages, $this->pageSize, $this->filter, $sortBy);
    }

    public function asQuery(): Query
    {
        return new Query([
            'skippages' => $this->skipPages,
            'pagesize' => $this->pageSize,
            'filter' => $this->filter,
            'sort' => $this->sortBy,
        ]);
    }
}
