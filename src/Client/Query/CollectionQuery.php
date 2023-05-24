<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Query;

use Webmozart\Assert\Assert;

final class CollectionQuery extends Query
{
    public function __construct(
        int $skipPages = 0,
        int $pageSize = 20,
        string $filter = null,
        string $sortBy = null,
    ) {
        Assert::greaterThanEq($skipPages, 0);
        Assert::greaterThanEq($pageSize, 1);

        parent::__construct([
            'skippages' => $skipPages,
            'pagesize' => $pageSize,
            'filter' => $filter,
            'sort' => $sortBy,
        ]);
    }
}
