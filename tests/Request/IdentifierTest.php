<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\NormalizerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    /**
     * @return iterable<string, array{Identifier, string, int|string}>
     */
    public static function integerFactoryProvider(): iterable
    {
        yield 'layout' => [Identifier::layout(17), 'layoutNumber', 17];
        yield 'paymentTerms' => [Identifier::paymentTerms(1), 'paymentTermsNumber', 1];
        yield 'customer' => [Identifier::customer(42), 'customerNumber', 42];
        yield 'customerGroup' => [Identifier::customerGroup(2), 'customerGroupNumber', 2];
        yield 'vatZone' => [Identifier::vatZone(3), 'vatZoneNumber', 3];
        yield 'project' => [Identifier::project(7), 'projectNumber', 7];
        yield 'deliveryLocation' => [Identifier::deliveryLocation(9), 'deliveryLocationNumber', 9];
        yield 'unit' => [Identifier::unit(2), 'unitNumber', 2];
        yield 'employee' => [Identifier::employee(11), 'employeeNumber', 11];
        yield 'customerContact' => [Identifier::customerContact(13), 'customerContactNumber', 13];
        yield 'vendor' => [Identifier::vendor(8), 'vendorNumber', 8];
        yield 'departmentalDistribution' => [Identifier::departmentalDistribution(99), 'departmentalDistributionNumber', 99];
    }

    /**
     * @param int|string $value
     */
    #[Test]
    #[DataProvider('integerFactoryProvider')]
    public function integer_factories_pin_the_correct_field_name(Identifier $identifier, string $expectedFieldName, $value): void
    {
        self::assertSame($expectedFieldName, $identifier->fieldName);
        self::assertSame($value, $identifier->value);
    }

    #[Test]
    public function from_reference_infers_the_field_name_from_the_single_number_key(): void
    {
        $identifier = Identifier::fromReference([
            'customerGroupNumber' => 7,
            'name' => 'Wholesale',
            'self' => 'https://restapi.e-conomic.com/customer-groups/7',
        ]);

        self::assertSame('customerGroupNumber', $identifier->fieldName);
        self::assertSame(7, $identifier->value);
    }

    #[Test]
    public function from_reference_accepts_string_valued_number_keys(): void
    {
        // products use a string productNumber — the value round-trips as the server sent it
        $identifier = Identifier::fromReference(['productNumber' => 'SKU-001', 'self' => 'https://example/products/SKU-001']);

        self::assertSame('productNumber', $identifier->fieldName);
        self::assertSame('SKU-001', $identifier->value);
    }

    #[Test]
    public function from_reference_ignores_nested_arrays_when_detecting_the_number_key(): void
    {
        // order references.customerContact carries a nested customer object whose
        // customerNumber must not make the detection ambiguous
        $identifier = Identifier::fromReference([
            'customerContactNumber' => 9,
            'customer' => ['customerNumber' => 42],
        ]);

        self::assertSame('customerContactNumber', $identifier->fieldName);
        self::assertSame(9, $identifier->value);
    }

    #[Test]
    public function from_reference_throws_when_no_number_key_is_present(): void
    {
        // e-conomic's priceGroup-style shape: a bare HATEOAS self link
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('found 0 (object keys: self)');

        Identifier::fromReference(['self' => 'https://example/pg/1']);
    }

    #[Test]
    public function from_reference_throws_when_multiple_number_keys_are_present(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('found 2');

        Identifier::fromReference(['customerNumber' => 1, 'employeeNumber' => 2]);
    }

    #[Test]
    public function from_reference_throws_when_the_number_value_is_not_a_scalar_identifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('found 0');

        Identifier::fromReference(['customerGroupNumber' => true]);
    }

    #[Test]
    public function product_factory_takes_a_string(): void
    {
        $identifier = Identifier::product('SKU-001');

        self::assertSame('productNumber', $identifier->fieldName);
        self::assertSame('SKU-001', $identifier->value);
    }

    #[Test]
    public function zero_integer_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::customer(0);
    }

    #[Test]
    public function negative_integer_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::customer(-1);
    }

    #[Test]
    public function empty_string_product_number_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identifier::product('');
    }

    #[Test]
    public function register_transformer_returns_a_builder_that_normalizes_identifier_to_keyed_pair(): void
    {
        // The contract: consumers supplying their own NormalizerBuilder call registerTransformer()
        // to wire the SDK's serialization rule. The returned builder MUST normalize any Identifier
        // instance as {<fieldName>: <value>}.
        $builder = Identifier::registerTransformer(new NormalizerBuilder());

        $json = $builder->normalizer(Format::json())->normalize(Identifier::layout(17));

        self::assertSame('{"layoutNumber":17}', $json);
    }

    #[Test]
    public function register_transformer_preserves_other_builder_configuration(): void
    {
        // registerTransformer is `@pure` and clones; the returned builder must still carry whatever
        // configuration the consumer applied before / after calling it. We can't observe internal
        // state directly, but we can register another transformer on top and verify both fire.
        $builder = new NormalizerBuilder()
            ->registerTransformer(
                /** @return array<string, int|string> */
                static fn (\DateTimeImmutable $d): array => ['date' => $d->format('Y-m-d')],
            )
        ;
        $builder = Identifier::registerTransformer($builder);

        // Identifier transformer still works:
        $identifierJson = $builder->normalizer(Format::json())->normalize(Identifier::customer(1));
        self::assertSame('{"customerNumber":1}', $identifierJson);

        // The DateTimeImmutable transformer registered earlier was preserved:
        $dateJson = $builder->normalizer(Format::json())->normalize(new \DateTimeImmutable('2026-05-27'));
        self::assertSame('{"date":"2026-05-27"}', $dateJson);
    }
}
