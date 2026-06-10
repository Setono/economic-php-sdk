<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Product;

/**
 * The schema's `accrual` (accrual account summary), `products` and `salesAccounts` (links) are
 * deliberately not typed — they stay reachable via `$product->raw['productGroup'][...]`.
 */
final readonly class ProductGroup
{
    public function __construct(
        public ?int $productGroupNumber = null,
        public ?string $name = null,
        public ?bool $inventoryEnabled = null,
        public ?string $self = null,
    ) {
    }
}
