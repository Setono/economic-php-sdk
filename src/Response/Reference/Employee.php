<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class Employee
{
    public function __construct(
        public ?int $employeeNumber = null,
        public ?string $self = null,
    ) {
    }
}
