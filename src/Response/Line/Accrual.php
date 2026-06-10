<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Line;

final readonly class Accrual
{
    public function __construct(
        public ?\DateTimeImmutable $startDate = null,
        public ?\DateTimeImmutable $endDate = null,
    ) {
    }
}
