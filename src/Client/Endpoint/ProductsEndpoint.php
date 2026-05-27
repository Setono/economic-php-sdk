<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Response\Product\Product;

/**
 * @extends CollectionEndpoint<Product>
 */
final class ProductsEndpoint extends CollectionEndpoint
{
    public function getByNumber(string $number): ?Product
    {
        return $this->getItem($number);
    }

    protected static function getPath(): string
    {
        return 'products';
    }

    /**
     * @return class-string<Product>
     */
    protected static function getItemClass(): string
    {
        return Product::class;
    }
}
