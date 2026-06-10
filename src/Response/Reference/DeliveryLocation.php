<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class DeliveryLocation
{
    public function __construct(
        public ?int $deliveryLocationNumber = null,
        public ?string $self = null,
    ) {
    }
}
