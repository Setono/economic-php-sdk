<?php

declare(strict_types=1);

namespace Setono\Economic\DTO;

final class Inventory
{
    public int $available = 0;

    public int $inStock = 0;

    public int $orderedByCustomers = 0;

    public int $orderedFromSuppliers = 0;
}
