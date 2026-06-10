<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Response\Customer\Customer;
use Setono\Economic\Response\Reference\CustomerContact;
use Setono\Economic\Response\Reference\CustomerGroup;
use Setono\Economic\Response\Reference\DeliveryLocation;
use Setono\Economic\Response\Reference\Employee;
use Setono\Economic\Response\Reference\Layout;
use Setono\Economic\Response\Reference\PaymentTerms;
use Setono\Economic\Response\Reference\VatZone;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(CustomersEndpoint::class)]
#[CoversClass(Customer::class)]
#[CoversClass(CustomerContact::class)]
#[CoversClass(CustomerGroup::class)]
#[CoversClass(DeliveryLocation::class)]
#[CoversClass(Employee::class)]
#[CoversClass(Layout::class)]
#[CoversClass(PaymentTerms::class)]
#[CoversClass(VatZone::class)]
final class CustomersFullMappingTest extends TestCase
{
    #[Test]
    public function every_first_level_customer_field_maps_to_a_typed_property(): void
    {
        $body = json_encode([
            'customerNumber' => 1,
            'name' => 'Acme',
            'currency' => 'DKK',
            'barred' => false,
            'lastUpdated' => '2020-02-19T09:18:09Z',
            'email' => 'billing@acme.test',
            'address' => 'Main Street 1',
            'zip' => '8000',
            'city' => 'Aarhus',
            'country' => 'Denmark',
            'corporateIdentificationNumber' => '12345678',
            'vatNumber' => 'DK12345678',
            'telephoneAndFaxNumber' => '+45 11111111',
            'mobilePhone' => '+45 22222222',
            'balance' => 42.5,
            'dueAmount' => 10.25,
            'creditLimit' => 1000.0,
            'pNumber' => '1234567890',
            'ean' => '5790000123456',
            'publicEntryNumber' => 'ENTRY-7',
            'eInvoicingDisabledByDefault' => true,
            'website' => 'https://acme.test',
            'defaultDeliveryLocation' => ['deliveryLocationNumber' => 3, 'self' => 'https://restapi.e-conomic.com/customers/1/delivery-locations/3'],
            'attention' => ['customerContactNumber' => 11, 'self' => 'https://restapi.e-conomic.com/customers/1/contacts/11'],
            'customerContact' => ['customerContactNumber' => 12, 'self' => 'https://restapi.e-conomic.com/customers/1/contacts/12'],
            'customerGroup' => ['customerGroupNumber' => 7, 'self' => 'https://restapi.e-conomic.com/customer-groups/7'],
            'layout' => ['layoutNumber' => 19, 'self' => 'https://restapi.e-conomic.com/layouts/19'],
            'paymentTerms' => ['paymentTermsNumber' => 2, 'self' => 'https://restapi.e-conomic.com/payment-terms/2'],
            'salesPerson' => ['employeeNumber' => 4, 'self' => 'https://restapi.e-conomic.com/employees/4'],
            'vatZone' => ['vatZoneNumber' => 1, 'self' => 'https://restapi.e-conomic.com/vat-zones/1'],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/customers/1', $body)
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $customer = $client->customers()->getByNumber(1);

        self::assertInstanceOf(Customer::class, $customer);

        self::assertSame('1234567890', $customer->pNumber);
        self::assertSame('5790000123456', $customer->ean);
        self::assertSame('ENTRY-7', $customer->publicEntryNumber);
        self::assertTrue($customer->eInvoicingDisabledByDefault);
        self::assertSame('https://acme.test', $customer->website);

        self::assertNotNull($customer->defaultDeliveryLocation);
        self::assertSame(3, $customer->defaultDeliveryLocation->deliveryLocationNumber);
        self::assertSame('https://restapi.e-conomic.com/customers/1/delivery-locations/3', $customer->defaultDeliveryLocation->self);

        self::assertNotNull($customer->attention);
        self::assertSame(11, $customer->attention->customerContactNumber);

        self::assertNotNull($customer->customerContact);
        self::assertSame(12, $customer->customerContact->customerContactNumber);

        self::assertNotNull($customer->customerGroup);
        self::assertSame(7, $customer->customerGroup->customerGroupNumber);
        self::assertSame('https://restapi.e-conomic.com/customer-groups/7', $customer->customerGroup->self);

        self::assertNotNull($customer->layout);
        self::assertSame(19, $customer->layout->layoutNumber);

        self::assertNotNull($customer->paymentTerms);
        self::assertSame(2, $customer->paymentTerms->paymentTermsNumber);
        self::assertSame('https://restapi.e-conomic.com/payment-terms/2', $customer->paymentTerms->self);

        self::assertNotNull($customer->salesPerson);
        self::assertSame(4, $customer->salesPerson->employeeNumber);

        self::assertNotNull($customer->vatZone);
        self::assertSame(1, $customer->vatZone->vatZoneNumber);
    }

    #[Test]
    public function raw_still_carries_the_full_body_including_typed_fields(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/customers/1',
                '{"customerNumber":1,"paymentTerms":{"paymentTermsNumber":2},"self":"https://restapi.e-conomic.com/customers/1"}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $customer = $client->customers()->getByNumber(1);

        self::assertInstanceOf(Customer::class, $customer);
        self::assertSame(
            [
                'customerNumber' => 1,
                'paymentTerms' => ['paymentTermsNumber' => 2],
                'self' => 'https://restapi.e-conomic.com/customers/1',
            ],
            $customer->raw,
        );
    }
}
