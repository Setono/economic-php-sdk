<?php

declare(strict_types=1);

namespace Setono\Economic\DTO;

/**
 * @deprecated The inventory module is deprecated in e-conomic and will be removed. Migrate your code to not use this information.
 */
final class Inventory
{
    public float $available = 0;

    public float $inStock = 0;

    public float $orderedByCustomers = 0;

    public float $orderedFromSuppliers = 0;
}
