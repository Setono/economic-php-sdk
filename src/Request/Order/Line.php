<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Payload;

/**
 * A single order line. All fields are optional per the schema — `description` is
 * conditionally required when referencing an existing product, which the server
 * enforces; the SDK does not duplicate that rule. Read-only computed line totals
 * (`marginInBaseCurrency`, `marginPercentage`) are intentionally absent: they
 * belong on the response side, not the request body.
 *
 * `product` uses a string `productNumber`; `unit` and `departmentalDistribution`
 * use integers. All three are constructed via {@see Identifier} factories.
 */
final class Line implements Payload
{
    public function __construct(
        public ?int $lineNumber = null,
        public ?int $sortKey = null,
        public ?string $description = null,
        public ?Accrual $accrual = null,
        public ?Identifier $unit = null,
        public ?Identifier $product = null,
        public ?float $quantity = null,
        public ?float $unitNetPrice = null,
        public ?float $discountPercentage = null,
        public ?float $unitCostPrice = null,
        public ?Identifier $departmentalDistribution = null,
    ) {
    }
}
