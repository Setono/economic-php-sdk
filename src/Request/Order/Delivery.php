<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use Setono\Economic\Request\Payload;

/**
 * Delivery details for the order. All fields are optional per the e-conomic schema.
 * `deliveryDate` is ISO-8601 (YYYY-MM-DD).
 */
final class Delivery implements Payload
{
    public function __construct(
        public ?string $address = null,
        public ?string $zip = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $deliveryTerms = null,
        public ?string $deliveryDate = null,
    ) {
    }
}
