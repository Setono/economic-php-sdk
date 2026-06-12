<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint\Orders;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Response\Document\Delivery;
use Setono\Economic\Response\Document\Notes;
use Setono\Economic\Response\Document\Pdf;
use Setono\Economic\Response\Document\Recipient;
use Setono\Economic\Response\Document\References;
use Setono\Economic\Response\Line\Accrual;
use Setono\Economic\Response\Line\Line;
use Setono\Economic\Response\Order\Order;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(DraftOrdersEndpoint::class)]
#[CoversClass(SentOrdersEndpoint::class)]
#[CoversClass(Order::class)]
#[CoversClass(Line::class)]
#[CoversClass(Accrual::class)]
#[CoversClass(Recipient::class)]
#[CoversClass(Delivery::class)]
#[CoversClass(Notes::class)]
#[CoversClass(References::class)]
#[CoversClass(Pdf::class)]
final class DraftOrdersFullMappingTest extends TestCase
{
    #[Test]
    public function every_first_level_order_field_maps_to_a_typed_property(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/orders/drafts/9912', self::fullOrderBody())
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $order = $client->orders()->drafts()->getByNumber(9912);

        self::assertInstanceOf(Order::class, $order);

        self::assertSame(9912, $order->orderNumber);
        // Date-only fields must map to exactly midnight UTC — deterministic regardless of
        // the wall clock the test runs at (the `!` in the SDK's `!Y-m-d` date format).
        self::assertNotNull($order->date);
        self::assertSame('2026-05-01T00:00:00+00:00', $order->date->format('Y-m-d\TH:i:sP'));
        self::assertNotNull($order->dueDate);
        self::assertSame('2026-05-09T00:00:00+00:00', $order->dueDate->format('Y-m-d\TH:i:sP'));
        self::assertNotNull($order->lastUpdated);
        self::assertSame('2026-04-30T11:22:33+00:00', $order->lastUpdated->format('Y-m-d\TH:i:sP'));

        self::assertSame('DKK', $order->currency);
        self::assertSame(100.0, $order->exchangeRate);
        self::assertSame(800.0, $order->netAmount);
        self::assertSame(800.0, $order->netAmountInBaseCurrency);
        self::assertSame(1000.0, $order->grossAmount);
        self::assertSame(1000.0, $order->grossAmountInBaseCurrency);
        self::assertSame(300.0, $order->marginInBaseCurrency);
        self::assertSame(37.5, $order->marginPercentage);
        self::assertSame(200.0, $order->vatAmount);
        self::assertSame(0.0, $order->roundingAmount);
        self::assertSame(500.0, $order->costPriceInBaseCurrency);

        self::assertNotNull($order->paymentTerms);
        self::assertSame(2, $order->paymentTerms->paymentTermsNumber);
        self::assertSame(8, $order->paymentTerms->daysOfCredit);
        self::assertSame('Netto 8 dage', $order->paymentTerms->name);
        self::assertSame('net', $order->paymentTerms->paymentTermsType);

        // `customer` maps into the full Customer DTO; RawStamper::stamp() recurses through the
        // mapped graph, so even this nested Resource carries its slice of the body on $raw.
        self::assertNotNull($order->customer);
        self::assertSame(1, $order->customer->customerNumber);
        self::assertSame('https://restapi.e-conomic.com/customers/1', $order->customer->raw['self']);

        self::assertNotNull($order->recipient);
        self::assertSame('Acme', $order->recipient->name);
        self::assertSame('Main Street 1', $order->recipient->address);
        self::assertSame('8000', $order->recipient->zip);
        self::assertSame('Aarhus', $order->recipient->city);
        self::assertSame('Denmark', $order->recipient->country);
        self::assertSame('5790000123456', $order->recipient->ean);
        self::assertSame('ENTRY-7', $order->recipient->publicEntryNumber);
        self::assertSame('12345678', $order->recipient->cvr);
        self::assertSame('ean', $order->recipient->nemHandelType);
        self::assertNotNull($order->recipient->attention);
        self::assertSame(11, $order->recipient->attention->customerContactNumber);
        self::assertNotNull($order->recipient->vatZone);
        self::assertSame(1, $order->recipient->vatZone->vatZoneNumber);

        self::assertNotNull($order->deliveryLocation);
        self::assertSame(3, $order->deliveryLocation->deliveryLocationNumber);

        self::assertNotNull($order->delivery);
        self::assertSame('Warehouse Road 2', $order->delivery->address);
        self::assertSame('2100', $order->delivery->zip);
        self::assertSame('København Ø', $order->delivery->city);
        self::assertSame('Denmark', $order->delivery->country);
        self::assertSame('EXW', $order->delivery->deliveryTerms);
        self::assertNotNull($order->delivery->deliveryDate);
        self::assertSame('2026-05-15T00:00:00+00:00', $order->delivery->deliveryDate->format('Y-m-d\TH:i:sP'));

        self::assertNotNull($order->notes);
        self::assertSame('Order heading', $order->notes->heading);
        self::assertSame('First line', $order->notes->textLine1);
        self::assertSame('Second line', $order->notes->textLine2);

        // `references.customerContact.customer` exercises the recursive
        // Customer → CustomerContact → Customer type graph through Valinor.
        self::assertNotNull($order->references);
        self::assertNotNull($order->references->customerContact);
        self::assertSame(55, $order->references->customerContact->customerContactNumber);
        self::assertNotNull($order->references->customerContact->customer);
        self::assertSame(1, $order->references->customerContact->customer->customerNumber);
        self::assertNotNull($order->references->salesPerson);
        self::assertSame(4, $order->references->salesPerson->employeeNumber);
        self::assertNotNull($order->references->vendorReference);
        self::assertSame(6, $order->references->vendorReference->employeeNumber);
        self::assertSame('PO-2026-117', $order->references->other);

        self::assertNotNull($order->project);
        self::assertSame(14, $order->project->projectNumber);

        self::assertNotNull($order->layout);
        self::assertSame(19, $order->layout->layoutNumber);

        self::assertNotNull($order->pdf);
        self::assertSame('https://restapi.e-conomic.com/orders/drafts/9912/pdf', $order->pdf->download);

        self::assertCount(1, $order->lines);
        $line = $order->lines[0];
        self::assertSame(1, $line->lineNumber);
        self::assertSame(10, $line->sortKey);
        self::assertSame('Widget', $line->description);
        self::assertNotNull($line->product);
        self::assertSame('SKU-5', $line->product->productNumber);
        self::assertSame(2.0, $line->quantity);
        self::assertSame(400.0, $line->unitNetPrice);
        self::assertSame(10.0, $line->discountPercentage);
        self::assertSame(250.0, $line->unitCostPrice);
        self::assertSame(720.0, $line->totalNetAmount);
        self::assertSame(220.0, $line->marginInBaseCurrency);
        self::assertSame(30.56, $line->marginPercentage);
        self::assertNotNull($line->unit);
        self::assertSame(1, $line->unit->unitNumber);
        self::assertSame('stk.', $line->unit->name);
        self::assertNotNull($line->departmentalDistribution);
        self::assertSame(9, $line->departmentalDistribution->departmentalDistributionNumber);
        self::assertNotNull($line->accrual);
        self::assertNotNull($line->accrual->startDate);
        self::assertSame('2026-05-01T00:00:00+00:00', $line->accrual->startDate->format('Y-m-d\TH:i:sP'));
        self::assertNotNull($line->accrual->endDate);
        self::assertSame('2026-08-31T00:00:00+00:00', $line->accrual->endDate->format('Y-m-d\TH:i:sP'));
    }

    #[Test]
    public function sent_orders_map_into_the_same_order_dto(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/orders/sent/9913',
                '{"orderNumber":9913,"date":"2026-05-02","currency":"EUR","grossAmount":125.0}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $order = $client->orders()->sent()->getByNumber(9913);

        self::assertInstanceOf(Order::class, $order);
        self::assertSame(9913, $order->orderNumber);
        self::assertNotNull($order->date);
        self::assertSame('2026-05-02T00:00:00+00:00', $order->date->format('Y-m-d\TH:i:sP'));
        self::assertSame('EUR', $order->currency);
        self::assertSame(125.0, $order->grossAmount);
    }

    private static function fullOrderBody(): string
    {
        return json_encode([
            'orderNumber' => 9912,
            'date' => '2026-05-01',
            'currency' => 'DKK',
            'exchangeRate' => 100.0,
            'netAmount' => 800.0,
            'netAmountInBaseCurrency' => 800.0,
            'grossAmount' => 1000.0,
            'grossAmountInBaseCurrency' => 1000.0,
            'marginInBaseCurrency' => 300.0,
            'marginPercentage' => 37.5,
            'vatAmount' => 200.0,
            'roundingAmount' => 0.0,
            'costPriceInBaseCurrency' => 500.0,
            'dueDate' => '2026-05-09',
            'lastUpdated' => '2026-04-30T11:22:33Z',
            'paymentTerms' => [
                'paymentTermsNumber' => 2,
                'daysOfCredit' => 8,
                'name' => 'Netto 8 dage',
                'paymentTermsType' => 'net',
                'self' => 'https://restapi.e-conomic.com/payment-terms/2',
            ],
            'customer' => [
                'customerNumber' => 1,
                'self' => 'https://restapi.e-conomic.com/customers/1',
            ],
            'recipient' => [
                'name' => 'Acme',
                'address' => 'Main Street 1',
                'zip' => '8000',
                'city' => 'Aarhus',
                'country' => 'Denmark',
                'ean' => '5790000123456',
                'publicEntryNumber' => 'ENTRY-7',
                'attention' => ['customerContactNumber' => 11, 'self' => 'https://restapi.e-conomic.com/customers/1/contacts/11'],
                'vatZone' => ['vatZoneNumber' => 1, 'self' => 'https://restapi.e-conomic.com/vat-zones/1'],
                'cvr' => '12345678',
                'nemHandelType' => 'ean',
            ],
            'deliveryLocation' => [
                'deliveryLocationNumber' => 3,
                'self' => 'https://restapi.e-conomic.com/customers/1/delivery-locations/3',
            ],
            'delivery' => [
                'address' => 'Warehouse Road 2',
                'zip' => '2100',
                'city' => 'København Ø',
                'country' => 'Denmark',
                'deliveryTerms' => 'EXW',
                'deliveryDate' => '2026-05-15',
            ],
            'notes' => [
                'heading' => 'Order heading',
                'textLine1' => 'First line',
                'textLine2' => 'Second line',
            ],
            'references' => [
                'customerContact' => [
                    'customerContactNumber' => 55,
                    'customer' => ['customerNumber' => 1, 'self' => 'https://restapi.e-conomic.com/customers/1'],
                    'self' => 'https://restapi.e-conomic.com/customers/1/contacts/55',
                ],
                'salesPerson' => ['employeeNumber' => 4, 'self' => 'https://restapi.e-conomic.com/employees/4'],
                'vendorReference' => ['employeeNumber' => 6, 'self' => 'https://restapi.e-conomic.com/employees/6'],
                'other' => 'PO-2026-117',
            ],
            'project' => ['projectNumber' => 14, 'self' => 'https://restapi.e-conomic.com/projects/14'],
            'layout' => ['layoutNumber' => 19, 'self' => 'https://restapi.e-conomic.com/layouts/19'],
            'pdf' => ['download' => 'https://restapi.e-conomic.com/orders/drafts/9912/pdf'],
            'lines' => [
                [
                    'lineNumber' => 1,
                    'sortKey' => 10,
                    'description' => 'Widget',
                    'product' => ['productNumber' => 'SKU-5', 'self' => 'https://restapi.e-conomic.com/products/SKU-5'],
                    'quantity' => 2.0,
                    'unitNetPrice' => 400.0,
                    'discountPercentage' => 10.0,
                    'unitCostPrice' => 250.0,
                    'totalNetAmount' => 720.0,
                    'marginInBaseCurrency' => 220.0,
                    'marginPercentage' => 30.56,
                    'unit' => ['unitNumber' => 1, 'name' => 'stk.', 'self' => 'https://restapi.e-conomic.com/units/1'],
                    'departmentalDistribution' => ['departmentalDistributionNumber' => 9, 'distributionType' => 'department'],
                    'accrual' => ['startDate' => '2026-05-01', 'endDate' => '2026-08-31'],
                ],
            ],
            'soap' => ['orderHandle' => ['id' => 9912]],
            'self' => 'https://restapi.e-conomic.com/orders/drafts/9912',
        ], \JSON_THROW_ON_ERROR);
    }
}
