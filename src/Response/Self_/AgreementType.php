<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

final readonly class AgreementType
{
    public function __construct(
        public ?int $agreementTypeNumber = null,
        public ?string $name = null,
    ) {
    }
}
