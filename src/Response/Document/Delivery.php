<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Document;

final readonly class Delivery
{
    public function __construct(
        public ?string $address = null,
        public ?string $zip = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $deliveryTerms = null,
        public ?\DateTimeImmutable $deliveryDate = null,
    ) {
    }
}
