<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

final readonly class DepartmentalDistribution
{
    public function __construct(
        public ?int $departmentalDistributionNumber = null,
        public ?string $distributionType = null,
        public ?string $self = null,
    ) {
    }
}
