<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class Layout
{
    public function __construct(
        public ?int $layoutNumber = null,
        public ?string $self = null,
    ) {
    }
}
