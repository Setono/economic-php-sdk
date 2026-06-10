<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Document;

use Setono\Economic\Response\Reference\CustomerContact;
use Setono\Economic\Response\Reference\Employee;

final readonly class References
{
    public function __construct(
        public ?CustomerContact $customerContact = null,
        public ?Employee $salesPerson = null,
        public ?Employee $vendorReference = null,
        public ?string $other = null,
    ) {
    }
}
