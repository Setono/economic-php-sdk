<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Product;

final class Product
{
    public ?string $productNumber = null;

    public ?string $name = null;

    public ?float $salesPrice = null;

    public ?Inventory $inventory = null;
}
