<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Customer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Request\Identifier;

#[CoversClass(CustomerRequest::class)]
final class CustomerRequestTest extends TestCase
{
    #[Test]
    public function required_only_construction_succeeds(): void
    {
        $request = new CustomerRequest(
            name: 'Acme',
            currency: 'DKK',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );

        self::assertSame('Acme', $request->name);
        self::assertSame('DKK', $request->currency);
        self::assertNull($request->customerNumber);
        self::assertNull($request->email);
        self::assertNull($request->layout);
    }

    #[Test]
    public function empty_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomerRequest(
            name: '',
            currency: 'DKK',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );
    }

    #[Test]
    public function empty_currency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomerRequest(
            name: 'Acme',
            currency: '',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );
    }

    #[Test]
    public function four_character_currency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomerRequest(
            name: 'Acme',
            currency: 'EUR ',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );
    }

    #[Test]
    public function two_character_currency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomerRequest(
            name: 'Acme',
            currency: 'DK',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );
    }

    #[Test]
    public function whitespace_only_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomerRequest(
            name: '   ',
            currency: 'DKK',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );
    }

    #[Test]
    public function whitespace_only_currency_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomerRequest(
            name: 'Acme',
            currency: '   ',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );
    }

    #[Test]
    public function price_group_is_not_exposed_on_the_request_dto(): void
    {
        // The schema's `priceGroup` field has no `priceGroupNumber`, breaking the universal
        // `{<x>Number: int}` identifier convention. The DTO deliberately omits it; consumers
        // needing to set priceGroup use Client::post('customers', ...) directly.
        $reflection = new \ReflectionClass(CustomerRequest::class);

        self::assertFalse(
            $reflection->hasProperty('priceGroup'),
            'CustomerRequest must NOT expose a $priceGroup property — schema shape is ambiguous; '
            . 'consumers needing it drop down to Client::post() directly.',
        );
    }
}
