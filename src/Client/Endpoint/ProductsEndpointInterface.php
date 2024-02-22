<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Product\Product;

interface ProductsEndpointInterface extends EndpointInterface
{
    public function getByNumber(string $number): ?Product;

    /**
     * @return Collection<Product>
     */
    public function get(CollectionRequestOptions $collectionRequestOptions = null): Collection;
}
