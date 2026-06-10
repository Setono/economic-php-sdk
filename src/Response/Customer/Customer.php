<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Customer;

use Setono\Economic\Response\Reference\CustomerContact;
use Setono\Economic\Response\Reference\CustomerGroup;
use Setono\Economic\Response\Reference\DeliveryLocation;
use Setono\Economic\Response\Reference\Employee;
use Setono\Economic\Response\Reference\Layout;
use Setono\Economic\Response\Reference\PaymentTerms;
use Setono\Economic\Response\Reference\VatZone;
use Setono\Economic\Response\Resource;

/**
 * Entry-point response DTO for a single customer. Every first-level field of the customer
 * schema is typed except the HATEOAS link/meta fields (`self`, `contacts`, `deliveryLocations`,
 * `templates`, `totals`, `invoices`, `metaData`), which stay reachable via {@see Resource::$raw}.
 *
 * `final class` (NOT `final readonly class`) so `$raw` can be assigned after construction —
 * see {@see Resource} for the rationale; `rector.php` skips this from `ReadOnlyClassRector`.
 */
final class Customer extends Resource
{
    public function __construct(
        // identity / metadata
        public readonly ?int $customerNumber = null,
        public readonly ?string $name = null,
        public readonly ?string $currency = null,
        public readonly ?bool $barred = null,
        // The original wire string remains available at `$raw['lastUpdated']`.
        public readonly ?\DateTimeImmutable $lastUpdated = null,
        // contact & address
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?string $zip = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly ?string $corporateIdentificationNumber = null,
        public readonly ?string $vatNumber = null,
        public readonly ?string $telephoneAndFaxNumber = null,
        public readonly ?string $mobilePhone = null,
        // financial state (server-computed)
        public readonly ?float $balance = null,
        public readonly ?float $dueAmount = null,
        public readonly ?float $creditLimit = null,
        // electronic invoicing & registration numbers
        public readonly ?string $pNumber = null,
        public readonly ?string $ean = null,
        public readonly ?string $publicEntryNumber = null,
        public readonly ?bool $eInvoicingDisabledByDefault = null,
        public readonly ?string $website = null,
        // references
        public readonly ?DeliveryLocation $defaultDeliveryLocation = null,
        public readonly ?CustomerContact $attention = null,
        public readonly ?CustomerContact $customerContact = null,
        public readonly ?CustomerGroup $customerGroup = null,
        public readonly ?Layout $layout = null,
        public readonly ?PaymentTerms $paymentTerms = null,
        public readonly ?Employee $salesPerson = null,
        public readonly ?VatZone $vatZone = null,
    ) {
    }
}
