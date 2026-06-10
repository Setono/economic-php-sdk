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
