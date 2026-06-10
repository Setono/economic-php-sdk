<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Document;

use Setono\Economic\Response\Reference\CustomerContact;
use Setono\Economic\Response\Reference\VatZone;

/**
 * The actual recipient of an order/invoice document.
 *
 * `$nemHandelType` is deliberately a plain string, NOT the `Request\Order\NemHandelType` enum:
 * a backed enum on the read side would throw a MappingError the day e-conomic introduces a new
 * value. The enum stays write-side only, where it validates outgoing payloads.
 */
final readonly class Recipient
{
    public function __construct(
        public ?string $name = null,
        public ?string $address = null,
        public ?string $zip = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $ean = null,
        public ?string $publicEntryNumber = null,
        public ?CustomerContact $attention = null,
        public ?VatZone $vatZone = null,
        public ?string $cvr = null,
        public ?string $nemHandelType = null,
    ) {
    }
}
