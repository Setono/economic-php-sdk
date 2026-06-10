<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class VatZone
{
    public function __construct(
        public ?int $vatZoneNumber = null,
        public ?string $self = null,
    ) {
    }
}
