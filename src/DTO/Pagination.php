<?php

declare(strict_types=1);

namespace Setono\Economic\DTO;

final class Pagination
{
    public function __construct(
        public readonly int $maxPageSizeAllowed,
        public readonly int $skipPages,
        public readonly int $pageSize,
        public readonly int $results,
        public readonly int $resultsWithoutFilter,
        public readonly ?Page $firstPage = null,
        public readonly ?Page $lastPage = null,
        public readonly ?Page $nextPage = null,
    ) {
    }
}
