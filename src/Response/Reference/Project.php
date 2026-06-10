<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class Project
{
    public function __construct(
        public ?int $projectNumber = null,
        public ?string $self = null,
    ) {
    }
}
