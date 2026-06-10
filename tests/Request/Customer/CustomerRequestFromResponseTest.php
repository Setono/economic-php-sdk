<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Customer;

use CuyZ\Valinor\Mapper\MappingError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Payload;
use Setono\Economic\Response\Customer\Customer;

#[CoversClass(Payload::class)]
#[CoversClass(Identifier::class)]
#[CoversClass(CustomerRequest::class)]
final class CustomerRequestFromResponseTest extends TestCase
{
    #[Test]
    public function it_carries_over_every_modeled_field(): void
    {
        $request = CustomerRequest::fromResponse(self::richCustomer());

        self::assertSame('Acme', $request->name);
        self::assertSame('DKK', $request->currency);
        self::assertSame(['customerGroupNumber' => 1], [$request->customerGroup->fieldName => $request->customerGroup->value]);
        self::assertSame(['vatZoneNumber' => 2], [$request->vatZone->fieldName => $request->vatZone->value]);
        self::assertSame(['paymentTermsNumber' => 3], [$request->paymentTerms->fieldName => $request->paymentTerms->value]);
        self::assertSame(1, $request->customerNumber);
        self::assertFalse($request->barred);
        self::assertSame('Main 1', $request->address);
        self::assertSame('Aalborg', $request->city);
        self::assertSame('Denmark', $request->country);
        self::assertSame('9000', $request->zip);
        self::assertSame('12345678', $request->corporateIdentificationNumber);
        self::assertSame('1007331700', $request->pNumber);
        self::assertSame(1000.5, $request->creditLimit);
        self::assertSame('5790000123456', $request->ean);
        self::assertSame('foo@example.com', $request->email);
        self::assertNotNull($request->layout);
        self::assertSame(['layoutNumber' => 17], [$request->layout->fieldName => $request->layout->value]);
        self::assertSame('PEN-1', $request->publicEntryNumber);
        self::assertSame('+45 11111111', $request->telephoneAndFaxNumber);
        self::assertSame('+45 22222222', $request->mobilePhone);
        self::assertTrue($request->eInvoicingDisabledByDefault);
        self::assertSame('DK12345678', $request->vatNumber);
        self::assertSame('https://acme.example.com', $request->website);
        self::assertNotNull($request->salesPerson);
        self::assertSame(['employeeNumber' => 5], [$request->salesPerson->fieldName => $request->salesPerson->value]);
    }

    #[Test]
    public function it_is_mutable_after_prefill(): void
    {
        $request = CustomerRequest::fromResponse(self::richCustomer());

        $request->email = 'new@example.com';
        $request->mobilePhone = null;

        self::assertSame('new@example.com', $request->email);
        self::assertNull($request->mobilePhone);
    }

    #[Test]
    public function absent_optional_raw_keys_default_to_null(): void
    {
        $request = CustomerRequest::fromResponse(self::customerWithRaw(self::minimalRaw()));

        self::assertNull($request->pNumber);
        self::assertNull($request->ean);
        self::assertNull($request->publicEntryNumber);
        self::assertNull($request->website);
        self::assertNull($request->eInvoicingDisabledByDefault);
        self::assertNull($request->layout);
        self::assertNull($request->salesPerson);
        self::assertNull($request->barred);
        self::assertNull($request->email);
    }

    #[Test]
    public function explicit_null_reference_object_in_raw_maps_to_null(): void
    {
        $request = CustomerRequest::fromResponse(self::customerWithRaw(self::minimalRaw() + ['layout' => null]));

        self::assertNull($request->layout);
    }

    #[Test]
    public function it_throws_when_a_required_reference_object_is_missing_from_raw(): void
    {
        $raw = self::minimalRaw();
        unset($raw['customerGroup']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('customerGroup');

        CustomerRequest::fromResponse(self::customerWithRaw($raw));
    }

    #[Test]
    public function it_throws_on_a_hand_constructed_customer_with_empty_raw(): void
    {
        // Typed properties are ignored on purpose — fromResponse() maps from $raw alone,
        // and $raw is only populated on SDK-fetched resources.
        $customer = new Customer(customerNumber: 1, name: 'Acme', currency: 'DKK');

        try {
            CustomerRequest::fromResponse($customer);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('fetched through the SDK', $e->getMessage());
            self::assertInstanceOf(MappingError::class, $e->getPrevious(), 'the Valinor error must be preserved as $previous');
        }
    }

    #[Test]
    public function it_throws_when_a_required_scalar_is_missing_from_raw(): void
    {
        $raw = self::minimalRaw();
        unset($raw['currency']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('currency');

        CustomerRequest::fromResponse(self::customerWithRaw($raw));
    }

    #[Test]
    public function it_throws_when_a_reference_object_lacks_its_number_key_instead_of_silently_dropping_it(): void
    {
        // e-conomic's priceGroup-style shape: a bare HATEOAS self link with no number. Dropping
        // it silently would clear the field server-side on the subsequent full-replace PUT.
        $raw = self::minimalRaw() + ['layout' => ['self' => 'https://example/layouts/17']];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('layout');

        CustomerRequest::fromResponse(self::customerWithRaw($raw));
    }

    #[Test]
    public function it_throws_when_a_reference_object_is_not_an_array(): void
    {
        $raw = self::minimalRaw();
        $raw['vatZone'] = 'not-an-object';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vatZone');

        CustomerRequest::fromResponse(self::customerWithRaw($raw));
    }

    #[Test]
    public function it_throws_when_an_untyped_raw_scalar_has_the_wrong_type(): void
    {
        // The request mapper is strict — no scalar casting. A wrong-typed value must throw,
        // never be coerced or dropped.
        $raw = self::minimalRaw() + ['website' => 123];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('website');

        CustomerRequest::fromResponse(self::customerWithRaw($raw));
    }

    /**
     * The minimum `$raw` for fromResponse() to succeed: the request DTO's five required
     * constructor fields.
     *
     * @return array<string, mixed>
     */
    private static function minimalRaw(): array
    {
        return [
            'name' => 'Acme',
            'currency' => 'DKK',
            'customerGroup' => ['customerGroupNumber' => 1],
            'vatZone' => ['vatZoneNumber' => 2],
            'paymentTerms' => ['paymentTermsNumber' => 3],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function customerWithRaw(array $raw): Customer
    {
        $customer = new Customer();
        $customer->raw = $raw;

        return $customer;
    }

    private static function richCustomer(): Customer
    {
        return self::customerWithRaw([
            'customerNumber' => 1,
            'name' => 'Acme',
            'currency' => 'DKK',
            'barred' => false,
            'lastUpdated' => '2020-02-19T09:18:09Z', // server-computed — dropped as superfluous
            'email' => 'foo@example.com',
            'address' => 'Main 1',
            'zip' => '9000',
            'city' => 'Aalborg',
            'country' => 'Denmark',
            'corporateIdentificationNumber' => '12345678',
            'vatNumber' => 'DK12345678',
            'telephoneAndFaxNumber' => '+45 11111111',
            'mobilePhone' => '+45 22222222',
            'balance' => 100.5,
            'dueAmount' => 50.25,
            'creditLimit' => 1000.5,
            'pNumber' => '1007331700',
            'ean' => '5790000123456',
            'publicEntryNumber' => 'PEN-1',
            'website' => 'https://acme.example.com',
            'eInvoicingDisabledByDefault' => true,
            'customerGroup' => ['customerGroupNumber' => 1, 'self' => 'https://example/cg/1'],
            'vatZone' => ['vatZoneNumber' => 2, 'self' => 'https://example/vz/2'],
            'paymentTerms' => ['paymentTermsNumber' => 3, 'self' => 'https://example/pt/3'],
            'layout' => ['layoutNumber' => 17, 'self' => 'https://example/layouts/17'],
            'salesPerson' => ['employeeNumber' => 5, 'self' => 'https://example/employees/5'],
            'priceGroup' => ['self' => 'https://example/pg/1'], // unmodeled — dropped
            'invoices' => ['drafts' => 'https://example/inv/drafts'], // HATEOAS — dropped
            'self' => 'https://example/customers/1',
        ]);
    }
}
