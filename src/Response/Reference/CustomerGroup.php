<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class CustomerGroup
{
    public function __construct(
        public ?int $customerGroupNumber = null,
        public ?string $self = null,
    ) {
    }
}
