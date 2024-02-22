<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Product;

final class Inventory
{
    public float $available = 0;

    public float $inStock = 0;

    public float $orderedByCustomers = 0;

    public float $orderedFromSuppliers = 0;
}
