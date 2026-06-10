<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Line;

use Setono\Economic\Response\Product\Product;
use Setono\Economic\Response\Reference\DepartmentalDistribution;
use Setono\Economic\Response\Reference\Unit;

/**
 * Union of the order-line and invoice-line schemas; fields that only exist on one document
 * type (e.g. `accrual`/`margin*` on orders, `vatRate`/`vatAmount`/`deliveryDate` on invoices)
 * are simply `null` on the other.
 */
final readonly class Line
{
    public function __construct(
        public ?int $lineNumber = null,
        public ?Product $product = null,
        public ?float $quantity = null,
        public ?int $sortKey = null,
        public ?string $description = null,
        public ?\DateTimeImmutable $deliveryDate = null,
        public ?float $unitNetPrice = null,
        public ?float $discountPercentage = null,
        public ?float $unitCostPrice = null,
        public ?float $vatRate = null,
        public ?float $vatAmount = null,
        public ?float $totalNetAmount = null,
        public ?float $marginInBaseCurrency = null,
        public ?float $marginPercentage = null,
        public ?Accrual $accrual = null,
        public ?Unit $unit = null,
        public ?DepartmentalDistribution $departmentalDistribution = null,
    ) {
    }
}
