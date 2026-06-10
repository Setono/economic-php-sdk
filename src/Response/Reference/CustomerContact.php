<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Reference;

use Setono\Economic\Response\Customer\Customer;

/**
 * `$customer` is the full {@see Customer} DTO (precedent: `Line::$product`); references that
 * only carry `{customerNumber, self}` map into it fine, leaving the other fields `null`.
 */
final readonly class CustomerContact
{
    public function __construct(
        public ?int $customerContactNumber = null,
        public ?Customer $customer = null,
        public ?string $self = null,
    ) {
    }
}
