<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Client\Builder\SortBy;
use Setono\Economic\DTO\Collection;
use Setono\Economic\DTO\Product;

interface ProductsEndpointInterface extends EndpointInterface
{
    public function getByNumber(string $number): ?Product;

    /**
     * @return Collection<Product>
     */
    public function get(int $skipPages = 0, int $pageSize = 20, string $filter = null, string $sortBy = null): Collection;
}
