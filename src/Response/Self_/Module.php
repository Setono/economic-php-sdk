<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

final readonly class Module
{
    public function __construct(
        public ?int $moduleNumber = null,
        public ?string $name = null,
        public ?string $self = null,
    ) {
    }
}
