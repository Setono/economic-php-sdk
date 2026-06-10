<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

final readonly class Settings
{
    public function __construct(
        public ?string $baseCurrency = null,
        public ?string $defaultPaymentTerm = null,
        public ?string $internationalLedger = null,
    ) {
    }
}
