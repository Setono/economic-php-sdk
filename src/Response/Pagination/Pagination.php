<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Pagination;

final readonly class Pagination
{
    public function __construct(
        public int $maxPageSizeAllowed,
        public int $skipPages,
        public int $pageSize,
        public int $results,
        public int $resultsWithoutFilter,
        public ?Page $firstPage = null,
        public ?Page $lastPage = null,
        public ?Page $nextPage = null,
    ) {
    }
}
