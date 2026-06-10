<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Product;

/**
 * The four stock counts keep their historical `0` defaults (absent ⇒ zero stock). The
 * master-data fields below them default to `null` instead, because `0` is a meaningful
 * value for a weight, volume or price — absent must stay distinguishable from zero.
 */
final class Inventory
{
    public float $available = 0;

    public float $inStock = 0;

    public float $orderedByCustomers = 0;

    public float $orderedFromSuppliers = 0;

    public ?float $grossWeight = null;

    public ?float $netWeight = null;

    public ?float $packageVolume = null;

    public ?float $recommendedCostPrice = null;

    public ?\DateTimeImmutable $inventoryLastUpdated = null;
}
