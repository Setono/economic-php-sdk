<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Product;

use Setono\Economic\Response\Reference\DepartmentalDistribution;
use Setono\Economic\Response\Reference\Unit;
use Setono\Economic\Response\Resource;

final class Product extends Resource
{
    public function __construct(
        public readonly ?string $productNumber = null,
        public readonly ?string $name = null,
        public readonly ?float $salesPrice = null,
        public readonly ?Inventory $inventory = null,
        public readonly ?string $description = null,
        public readonly ?float $costPrice = null,
        public readonly ?float $recommendedPrice = null,
        public readonly ?string $barCode = null,
        public readonly ?bool $barred = null,
        public readonly ?\DateTimeImmutable $lastUpdated = null,
        public readonly ?Unit $unit = null,
        public readonly ?ProductGroup $productGroup = null,
        public readonly ?DepartmentalDistribution $departmentalDistribution = null,
    ) {
    }
}
