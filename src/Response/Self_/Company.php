<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

final readonly class Company
{
    public function __construct(
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $attention = null,
        public ?string $city = null,
        public ?string $companyIdentificationNumber = null,
        public ?string $country = null,
        public ?string $email = null,
        public ?string $name = null,
        public ?string $phoneNumber = null,
        public ?string $vatNumber = null,
        public ?string $website = null,
        public ?string $zip = null,
    ) {
    }
}
