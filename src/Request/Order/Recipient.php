<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Payload;
use Webmozart\Assert\Assert;

/**
 * The recipient block of a draft order. `name` and `vatZone` are required per the
 * schema; everything else is optional.
 *
 * `attention` references a customer contact and is constructed via
 * `Identifier::customerContact(int)`. `vatZone` is constructed via
 * `Identifier::vatZone(int)`.
 */
final class Recipient implements Payload
{
    public function __construct(
        public string $name,
        public Identifier $vatZone,
        public ?string $address = null,
        public ?string $zip = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $ean = null,
        public ?string $publicEntryNumber = null,
        public ?Identifier $attention = null,
        public ?string $mobilePhone = null,
        public ?NemHandelType $nemHandelType = null,
    ) {
        Assert::stringNotEmpty(trim($this->name), 'Recipient::$name must not be empty or whitespace-only');
    }
}
