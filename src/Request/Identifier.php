<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use CuyZ\Valinor\NormalizerBuilder;
use Webmozart\Assert\Assert;

/**
 * Generic foreign-key wrapper for the SDK's typed request DTOs. Wraps an identifier
 * (integer or string, depending on the resource) together with the JSON field name
 * it occupies in the e-conomic schema (e.g. `'layoutNumber'`, `'productNumber'`, …).
 *
 * Construction is via the named static factories only — one per reference type the
 * schema exercises. The factory determines the JSON field name and the value type
 * (most resources use integer identifiers; `product` uses a string `productNumber`).
 *
 * The default {@see NormalizerBuilder} produced by {@see \Setono\Economic\Client\Client}
 * has a transformer registered that serializes every `Identifier` instance as
 * `{<fieldName>: <value>}`. Consumers supplying their own `NormalizerBuilder` MUST
 * register the transformer via {@see self::registerTransformer()}.
 */
final readonly class Identifier
{
    /** @param non-empty-string $fieldName */
    private function __construct(
        public string $fieldName,
        public int|string $value,
    ) {
        // `$fieldName` is non-empty-string per the docblock and is only ever set by the named
        // factories below with hardcoded literals — no need to re-assert at runtime.
        if (is_int($this->value)) {
            Assert::positiveInteger($this->value);
        } else {
            Assert::stringNotEmpty(trim($this->value), 'Identifier value must not be empty or whitespace-only');
        }
    }

    public static function layout(int $number): self
    {
        return new self('layoutNumber', $number);
    }

    public static function paymentTerms(int $number): self
    {
        return new self('paymentTermsNumber', $number);
    }

    public static function customer(int $number): self
    {
        return new self('customerNumber', $number);
    }

    public static function customerGroup(int $number): self
    {
        return new self('customerGroupNumber', $number);
    }

    public static function vatZone(int $number): self
    {
        return new self('vatZoneNumber', $number);
    }

    public static function project(int $number): self
    {
        return new self('projectNumber', $number);
    }

    public static function deliveryLocation(int $number): self
    {
        return new self('deliveryLocationNumber', $number);
    }

    public static function product(string $number): self
    {
        return new self('productNumber', $number);
    }

    public static function unit(int $number): self
    {
        return new self('unitNumber', $number);
    }

    public static function employee(int $number): self
    {
        return new self('employeeNumber', $number);
    }

    public static function customerContact(int $number): self
    {
        return new self('customerContactNumber', $number);
    }

    public static function vendor(int $number): self
    {
        return new self('vendorNumber', $number);
    }

    public static function departmentalDistribution(int $number): self
    {
        return new self('departmentalDistributionNumber', $number);
    }

    /**
     * Build an `Identifier` from an e-conomic reference object as returned by the REST API —
     * e.g. `['customerGroupNumber' => 1, 'self' => 'https://...']`. The JSON field name is
     * inferred from the object's single scalar `<x>Number` key, so one factory covers every
     * reference type the API returns (including the string-valued `productNumber`) and the
     * identifier round-trips back to the exact shape the server itself produced.
     *
     * Registered as a Valinor constructor on the request mapper behind
     * {@see Payload::fromResponse()}. Prefer the named factories above in hand-written code.
     *
     * @param array<mixed> $reference
     *
     * @throws \InvalidArgumentException if the reference object does not contain exactly one scalar `<x>Number` key
     */
    public static function fromReference(array $reference): self
    {
        $candidates = [];
        foreach ($reference as $field => $value) {
            if (is_string($field) && str_ends_with($field, 'Number') && (is_int($value) || is_string($value))) {
                $candidates[$field] = $value;
            }
        }

        if (1 !== \count($candidates)) {
            throw new \InvalidArgumentException(sprintf(
                'Expected the reference object to contain exactly one scalar `<x>Number` key, found %d (object keys: %s)',
                \count($candidates),
                implode(', ', array_map(strval(...), array_keys($reference))),
            ));
        }

        $field = array_key_first($candidates);

        return new self($field, $candidates[$field]);
    }

    /**
     * Append the SDK's `Identifier` transformer to a consumer-supplied {@see NormalizerBuilder}.
     *
     * For consumers wiring a custom `NormalizerBuilder`, prefer the higher-level
     * `Client::registerNormalizerTransformers()` which registers both the `Identifier`
     * transformer AND the `Payload` null-skipper in one call.
     *
     * Note: {@see NormalizerBuilder::registerTransformer()} is `@pure` and clones the builder, so
     * the returned builder MUST be the one passed to the `Client` constructor.
     *
     * Note: Valinor matches transformers in last-registered-first order. Registering a competing
     * transformer for `Identifier` AFTER this call will shadow the SDK's rule without warning,
     * producing JSON that e-conomic will reject.
     */
    public static function registerTransformer(NormalizerBuilder $builder): NormalizerBuilder
    {
        return $builder->registerTransformer(
            /** @return array<string, int|string> */
            static fn (self $identifier): array => [$identifier->fieldName => $identifier->value],
        );
    }
}
