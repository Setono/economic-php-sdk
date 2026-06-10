<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class Unit
{
    public function __construct(
        public ?int $unitNumber = null,
        public ?string $name = null,
        public ?string $self = null,
    ) {
    }
}
