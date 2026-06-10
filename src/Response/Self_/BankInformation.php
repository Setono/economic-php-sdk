<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

final readonly class BankInformation
{
    public function __construct(
        public ?string $bankAccountNumber = null,
        public ?string $bankGiroNumber = null,
        public ?string $bankName = null,
        public ?string $bankSortCode = null,
        public ?string $pbsCustomerGroupNumber = null,
        public ?string $pbsFiSupplierNumber = null,
    ) {
    }
}
