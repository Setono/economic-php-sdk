<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Product;

use Setono\Economic\Response\Resource;

final class Product extends Resource
{
    public function __construct(
        public readonly ?string $productNumber = null,
        public readonly ?string $name = null,
        public readonly ?float $salesPrice = null,
        public readonly ?Inventory $inventory = null,
    ) {
    }
}
