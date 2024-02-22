<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\DTO\Collection;
use Setono\Economic\DTO\Product;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Request\CollectionRequestOptions;

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

    public function get(CollectionRequestOptions $collectionRequestOptions = null): Collection
    {
        $collectionRequestOptions ??= new CollectionRequestOptions();

        /** @var class-string<Collection<Product>> $collection */
        $collection = 'Setono\Economic\DTO\Collection<Setono\Economic\DTO\Product>';

        return $this->mapperBuilder->mapper()->map(
            $collection,
            $this->createSourceFromResponse($this->client->get(
                'products',
                $collectionRequestOptions->asQuery(),
            )),
        );
    }
}
