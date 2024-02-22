<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Product\Product;

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
        $collection = 'Setono\Economic\Response\Collection\Collection<Setono\Economic\Response\Product\Product>';

        return $this->mapperBuilder->mapper()->map(
            $collection,
            $this->createSourceFromResponse($this->client->get(
                'products',
                $collectionRequestOptions->asQuery(),
            )),
        );
    }
}
