<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Client\Builder\SortBy;
use Setono\Economic\DTO\Collection;
use Setono\Economic\DTO\Product;
use Setono\Economic\Exception\NotFoundException;

final class ProductsEndpoint extends Endpoint implements ProductsEndpointInterface
{
    public function getByNumber(string $number): ?Product
    {
        try {
            $response = $this->client->get(sprintf('products/%s', $number));
        } catch (NotFoundException) {
            return null;
        }

        return $this->mapperBuilder->mapper()->map(
            Product::class,
            $this->createSourceFromResponse($response),
        );
    }

    public function get(int $skipPages = 0, int $pageSize = 20, string $filter = null, string $sortBy = null): Collection
    {
        /** @var class-string<Collection<Product>> $collection */
        $collection = 'Setono\Economic\DTO\Collection<Setono\Economic\DTO\Product>';

        $query = [
            'skippages' => $skipPages,
            'pagesize' => $pageSize,
        ];

        if(null !== $filter) {
            $query['filter'] = $filter;
        }

        if(null !== $sortBy) {
            $query['sort'] = $sortBy;
        }

        return $this->mapperBuilder->mapper()->map(
            $collection,
            $this->createSourceFromResponse($this->client->get('products', $query)),
        );
    }
}
