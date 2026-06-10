<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Customer;

use Setono\Economic\Response\Resource;

/**
 * Entry-point response DTO for a single customer. Typed fields cover identity / metadata,
 * contact + address, and server-computed financial state. Everything else (reference objects
 * like `customerGroup`, `vatZone`, `paymentTerms`, `layout`, `salesPerson`, …; HATEOAS link
 * blobs; niche scalars like `pNumber`, `ean`, `publicEntryNumber`, `mobilePhone`, etc.) is
 * accessible via {@see Resource::$raw}.
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
        public readonly ?string $lastUpdated = null,
        // contact & address
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?string $zip = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly ?string $corporateIdentificationNumber = null,
        public readonly ?string $vatNumber = null,
        // financial state (server-computed)
        public readonly ?float $balance = null,
        public readonly ?float $dueAmount = null,
        public readonly ?float $creditLimit = null,
    ) {
    }
}
