<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\DTO\Collection;
use Setono\Economic\DTO\Product;
use Setono\Economic\Request\CollectionRequestOptions;

interface ProductsEndpointInterface extends EndpointInterface
{
    public function getByNumber(string $number): ?Product;

    /**
     * @return Collection<Product>
     */
    public function get(CollectionRequestOptions $collectionRequestOptions = null): Collection;
}
