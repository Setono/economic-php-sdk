<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use Webmozart\Assert\Assert;

final readonly class CollectionRequestOptions
{
    public function __construct(
        public int $skipPages = 0,
        public int $pageSize = 20,
        public ?Filter $filter = null,
        public ?string $sortBy = null,
    ) {
        Assert::greaterThanEq($skipPages, 0);
        Assert::greaterThanEq($pageSize, 1);
        Assert::lessThanEq($pageSize, 1000);
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

    public function withFilter(Filter $filter): self
    {
        return new self($this->skipPages, $this->pageSize, $filter, $this->sortBy);
    }

    public function withSortBy(string $sortBy): self
    {
        return new self($this->skipPages, $this->pageSize, $this->filter, $sortBy);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'skippages' => $this->skipPages,
            'pagesize' => $this->pageSize,
            'filter' => $this->filter?->toString(),
            'sort' => $this->sortBy,
        ];
    }
}
