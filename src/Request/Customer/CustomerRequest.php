<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Customer;

use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Payload;
use Setono\Economic\Response\Customer\Customer;
use Webmozart\Assert\Assert;

/**
 * Typed request body for `POST /customers` and `PUT /customers/:number`. Required fields are
 * non-nullable constructor arguments; optional fields default to `null` and are omitted from
 * the serialized JSON via the SDK's `Payload` null-skipping transformer.
 *
 * Deliberately mutable (NOT `readonly`): e-conomic updates are full-replace PUT, so the
 * read-modify-write flow is "prefill via the inherited {@see Payload::fromResponse()}
 * (`CustomerRequest::fromResponse($customer)`) → assign the fields to change →
 * `CustomersEndpoint::update()`". The constructor `Assert` guards run at construction
 * time only.
 *
 * Read-only server-computed fields (`balance`, `dueAmount`, `lastUpdated`, …) are intentionally
 * absent — they belong on the {@see Customer} response side.
 *
 * The schema's `priceGroup` field is NOT exposed here. Its schema shape is `{ self: string(uri) }`
 * with no `priceGroupNumber`, breaking the universal `{<x>Number: int}` identifier convention.
 * Consumers needing to set `priceGroup` use `Client::post('customers', $hand_built_payload)`
 * directly.
 *
 * Note on `eInvoicingDisabledByDefault`: per the e-conomic docs it is "updatable only by
 * using PATCH to /customers/:customerNumber" — mutating it on a prefilled request has no
 * effect through `update()` (PUT ignores it; it is not cleared by omission either).
 *
 * `customerNumber` is optional — when null, e-conomic auto-assigns one server-side.
 */
final class CustomerRequest extends Payload
{
    public function __construct(
        public string $name,
        public string $currency,
        public Identifier $customerGroup,
        public Identifier $vatZone,
        public Identifier $paymentTerms,
        public ?int $customerNumber = null,
        public ?bool $barred = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?string $zip = null,
        public ?string $corporateIdentificationNumber = null,
        public ?string $pNumber = null,
        public ?float $creditLimit = null,
        public ?string $ean = null,
        public ?string $email = null,
        public ?Identifier $layout = null,
        public ?string $publicEntryNumber = null,
        public ?string $telephoneAndFaxNumber = null,
        public ?string $mobilePhone = null,
        public ?bool $eInvoicingDisabledByDefault = null,
        public ?string $vatNumber = null,
        public ?string $website = null,
        public ?Identifier $salesPerson = null,
    ) {
        Assert::stringNotEmpty(trim($this->name), 'CustomerRequest::$name must not be empty or whitespace-only');
        Assert::stringNotEmpty(trim($this->currency), 'CustomerRequest::$currency must not be empty or whitespace-only');
        Assert::length($this->currency, 3);
    }
}
