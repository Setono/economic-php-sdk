<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Payload;

/**
 * The order's metadata block (the schema's `references` field). Distinct from
 * {@see Identifier} — these are pointers to people and a free-text field, not
 * to another resource by number alone.
 *
 * `salesPerson`, `customerContact`, and `vendorReference` are constructed via
 * `Identifier::employee()`, `Identifier::customerContact()`, and
 * `Identifier::vendor()` respectively.
 */
final readonly class References implements Payload
{
    public function __construct(
        public ?Identifier $salesPerson = null,
        public ?Identifier $customerContact = null,
        public ?Identifier $vendorReference = null,
        public ?string $other = null,
    ) {
    }
}
