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
 * read-modify-write flow is "prefill via {@see self::fromResponse()} → assign the fields to
 * change → `CustomersEndpoint::update()`". The constructor `Assert` guards run at construction
 * time only.
 *
 * Read-only server-computed fields (`balance`, `dueAmount`, `lastUpdated`, …) are intentionally
 * absent — they belong on the {@see Customer} response side.
 *
 * The schema's `priceGroup` field is NOT exposed here. Its schema shape is `{ self: string(uri) }`
 * with no `priceGroupNumber`, breaking the universal `{<x>Number: int}` identifier convention.
 * Consumers needing to set `priceGroup` use `Client::post('customers', $hand_built_payload)`
 * directly. See `openspec/changes/archive/<date>-create-customer/design.md` for the rationale.
 *
 * `customerNumber` is optional — when null, e-conomic auto-assigns one server-side.
 */
final class CustomerRequest implements Payload
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

    /**
     * Build a request prefilled from a fetched {@see Customer} — the safe starting point for
     * the read-modify-write flow that e-conomic's full-replace PUT semantics require:
     *
     * ```
     * $customer = $client->customers()->getByNumber(42);
     * $request = CustomerRequest::fromResponse($customer);
     * $request->email = 'new@example.com';   // change what you need
     * $request->mobilePhone = null;          // null = omitted from JSON = cleared server-side
     * $client->customers()->update(42, $request);
     * ```
     *
     * Typed response fields are copied directly; reference objects (`customerGroup`, `vatZone`,
     * `paymentTerms`, `layout`, `salesPerson`) and untyped scalars (`pNumber`, `ean`,
     * `publicEntryNumber`, `website`, `eInvoicingDisabledByDefault`) are extracted from
     * {@see Customer::$raw} — so `$existing` MUST come from an SDK fetch, not be hand-constructed.
     *
     * Raw data that is present but malformed throws rather than being silently dropped — a
     * dropped field would be cleared server-side on the subsequent full-replace PUT.
     *
     * WARNING: schema fields not modeled on this DTO (`priceGroup`, `customerContact`,
     * `attention`, `defaultDeliveryLocation`, …) cannot be carried over and WILL be cleared by
     * an update built from this request. Hand-build the body and use `Client::request()` if you
     * need to preserve them.
     *
     * Note on `eInvoicingDisabledByDefault`: per the e-conomic docs it is "updatable only by
     * using PATCH to /customers/:customerNumber" — mutating it on the prefilled request has no
     * effect through `update()` (PUT ignores it; it is not cleared by omission either).
     *
     * @throws \InvalidArgumentException if a required field is missing from `$existing` (null
     *     typed field or absent `$raw` reference object) or if present raw data is malformed
     */
    public static function fromResponse(Customer $existing): self
    {
        $raw = $existing->raw;

        Assert::notNull($existing->name, 'Customer::$name is null — fromResponse() requires a Customer fetched through the SDK, not a hand-constructed one.');
        Assert::notNull($existing->currency, 'Customer::$currency is null — fromResponse() requires a Customer fetched through the SDK, not a hand-constructed one.');

        return new self(
            name: $existing->name,
            currency: $existing->currency,
            customerGroup: self::requiredIdentifierFromRaw($raw, 'customerGroup', 'customerGroupNumber', Identifier::customerGroup(...)),
            vatZone: self::requiredIdentifierFromRaw($raw, 'vatZone', 'vatZoneNumber', Identifier::vatZone(...)),
            paymentTerms: self::requiredIdentifierFromRaw($raw, 'paymentTerms', 'paymentTermsNumber', Identifier::paymentTerms(...)),
            customerNumber: $existing->customerNumber,
            barred: $existing->barred,
            address: $existing->address,
            city: $existing->city,
            country: $existing->country,
            zip: $existing->zip,
            corporateIdentificationNumber: $existing->corporateIdentificationNumber,
            pNumber: self::nullableStringFromRaw($raw, 'pNumber'),
            creditLimit: $existing->creditLimit,
            ean: self::nullableStringFromRaw($raw, 'ean'),
            email: $existing->email,
            layout: self::optionalIdentifierFromRaw($raw, 'layout', 'layoutNumber', Identifier::layout(...)),
            publicEntryNumber: self::nullableStringFromRaw($raw, 'publicEntryNumber'),
            telephoneAndFaxNumber: $existing->telephoneAndFaxNumber,
            mobilePhone: $existing->mobilePhone,
            eInvoicingDisabledByDefault: self::nullableBoolFromRaw($raw, 'eInvoicingDisabledByDefault'),
            vatNumber: $existing->vatNumber,
            website: self::nullableStringFromRaw($raw, 'website'),
            salesPerson: self::optionalIdentifierFromRaw($raw, 'salesPerson', 'employeeNumber', Identifier::employee(...)),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param \Closure(int): Identifier $factory
     */
    private static function requiredIdentifierFromRaw(array $raw, string $key, string $numberKey, \Closure $factory): Identifier
    {
        Assert::keyExists($raw, $key, sprintf(
            'Customer::$raw[\'%s\'] is missing — fromResponse() requires a Customer fetched through the SDK, not a hand-constructed one.',
            $key,
        ));

        return self::identifierFromRaw($raw[$key], $key, $numberKey, $factory);
    }

    /**
     * @param array<string, mixed> $raw
     * @param \Closure(int): Identifier $factory
     */
    private static function optionalIdentifierFromRaw(array $raw, string $key, string $numberKey, \Closure $factory): ?Identifier
    {
        if (!\array_key_exists($key, $raw) || null === $raw[$key]) {
            return null;
        }

        return self::identifierFromRaw($raw[$key], $key, $numberKey, $factory);
    }

    /**
     * @param \Closure(int): Identifier $factory
     */
    private static function identifierFromRaw(mixed $value, string $key, string $numberKey, \Closure $factory): Identifier
    {
        Assert::isArray($value, sprintf('Expected Customer::$raw[\'%s\'] to be a reference object, got %s.', $key, get_debug_type($value)));
        Assert::keyExists($value, $numberKey, sprintf(
            'Customer::$raw[\'%s\'] has no \'%s\' key — refusing to silently drop the reference (a dropped field is cleared server-side on full-replace PUT).',
            $key,
            $numberKey,
        ));
        Assert::integer($value[$numberKey], sprintf('Expected Customer::$raw[\'%s\'][\'%s\'] to be an integer, got %s.', $key, $numberKey, get_debug_type($value[$numberKey])));

        return $factory($value[$numberKey]);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function nullableStringFromRaw(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;
        Assert::nullOrString($value, sprintf('Expected Customer::$raw[\'%s\'] to be a string or absent, got %s.', $key, get_debug_type($value)));

        return $value;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function nullableBoolFromRaw(array $raw, string $key): ?bool
    {
        $value = $raw[$key] ?? null;
        Assert::nullOrBoolean($value, sprintf('Expected Customer::$raw[\'%s\'] to be a boolean or absent, got %s.', $key, get_debug_type($value)));

        return $value;
    }
}
