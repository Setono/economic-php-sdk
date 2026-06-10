<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint\Invoices;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Response\Invoice\BookedInvoice;
use Setono\Economic\Response\Line\Line;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(BookedInvoicesEndpoint::class)]
#[CoversClass(BookedInvoice::class)]
#[CoversClass(Line::class)]
final class BookedInvoicesFullMappingTest extends TestCase
{
    #[Test]
    public function every_first_level_booked_invoice_field_maps_to_a_typed_property(): void
    {
        $body = json_encode([
            'bookedInvoiceNumber' => 333,
            'date' => '2026-04-01',
            'currency' => 'DKK',
            'exchangeRate' => 100.0,
            'netAmount' => 800.0,
            'netAmountInBaseCurrency' => 800.0,
            'grossAmount' => 1000.0,
            'grossAmountInBaseCurrency' => 1000.0,
            'vatAmount' => 200.0,
            'roundingAmount' => 0.0,
            'remainder' => 250.0,
            'remainderInBaseCurrency' => 250.0,
            'dueDate' => '2026-04-09',
            'paymentTerms' => [
                'paymentTermsNumber' => 2,
                'daysOfCredit' => 8,
                'name' => 'Netto 8 dage',
                'paymentTermsType' => 'net',
            ],
            'customer' => ['customerNumber' => 1, 'self' => 'https://restapi.e-conomic.com/customers/1'],
            'recipient' => [
                'name' => 'Acme',
                'address' => 'Main Street 1',
                'zip' => '8000',
                'city' => 'Aarhus',
                'country' => 'Denmark',
                'vatZone' => ['vatZoneNumber' => 1],
            ],
            'deliveryLocation' => ['deliveryLocationNumber' => 3],
            'delivery' => [
                'address' => 'Warehouse Road 2',
                'zip' => '2100',
                'city' => 'København Ø',
                'country' => 'Denmark',
                'deliveryTerms' => 'EXW',
                'deliveryDate' => '2026-04-15',
            ],
            'notes' => ['heading' => 'Invoice heading', 'textLine1' => 'First', 'textLine2' => 'Second'],
            'references' => [
                'customerContact' => ['customerContactNumber' => 55],
                'salesPerson' => ['employeeNumber' => 4],
                'vendorReference' => ['employeeNumber' => 6],
                'other' => 'PO-2026-117',
            ],
            'layout' => ['layoutNumber' => 19],
            'project' => ['projectNumber' => 14],
            'pdf' => ['download' => 'https://restapi.e-conomic.com/invoices/booked/333/pdf'],
            'sent' => 'https://restapi.e-conomic.com/invoices/sent/333',
            'lines' => [
                [
                    'lineNumber' => 1,
                    'sortKey' => 10,
                    'description' => 'Widget',
                    'deliveryDate' => '2026-04-15',
                    'product' => ['productNumber' => 'SKU-5'],
                    'quantity' => 2.0,
                    'unitNetPrice' => 400.0,
                    'discountPercentage' => 10.0,
                    'unitCostPrice' => 250.0,
                    'vatRate' => 25.0,
                    'vatAmount' => 180.0,
                    'totalNetAmount' => 720.0,
                    'unit' => ['unitNumber' => 1, 'name' => 'stk.'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/invoices/booked/333', $body)
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $invoice = $client->invoices()->booked()->getByNumber(333);

        self::assertInstanceOf(BookedInvoice::class, $invoice);

        self::assertSame(333, $invoice->bookedInvoiceNumber);
        self::assertNotNull($invoice->date);
        self::assertSame('2026-04-01T00:00:00+00:00', $invoice->date->format('Y-m-d\TH:i:sP'));
        self::assertSame('DKK', $invoice->currency);
        self::assertSame(100.0, $invoice->exchangeRate);
        self::assertSame(800.0, $invoice->netAmount);
        self::assertSame(800.0, $invoice->netAmountInBaseCurrency);
        self::assertSame(1000.0, $invoice->grossAmount);
        self::assertSame(1000.0, $invoice->grossAmountInBaseCurrency);
        self::assertSame(200.0, $invoice->vatAmount);
        self::assertSame(0.0, $invoice->roundingAmount);
        self::assertSame(250.0, $invoice->remainder);
        self::assertSame(250.0, $invoice->remainderInBaseCurrency);
        self::assertNotNull($invoice->dueDate);
        self::assertSame('2026-04-09T00:00:00+00:00', $invoice->dueDate->format('Y-m-d\TH:i:sP'));

        self::assertNotNull($invoice->paymentTerms);
        self::assertSame(2, $invoice->paymentTerms->paymentTermsNumber);
        self::assertNotNull($invoice->customer);
        self::assertSame(1, $invoice->customer->customerNumber);
        self::assertNotNull($invoice->recipient);
        self::assertSame('Acme', $invoice->recipient->name);
        self::assertNotNull($invoice->recipient->vatZone);
        self::assertSame(1, $invoice->recipient->vatZone->vatZoneNumber);
        self::assertNotNull($invoice->deliveryLocation);
        self::assertSame(3, $invoice->deliveryLocation->deliveryLocationNumber);
        self::assertNotNull($invoice->delivery);
        self::assertNotNull($invoice->delivery->deliveryDate);
        self::assertSame('2026-04-15T00:00:00+00:00', $invoice->delivery->deliveryDate->format('Y-m-d\TH:i:sP'));
        self::assertNotNull($invoice->notes);
        self::assertSame('Invoice heading', $invoice->notes->heading);
        self::assertNotNull($invoice->references);
        self::assertSame('PO-2026-117', $invoice->references->other);
        self::assertNotNull($invoice->layout);
        self::assertSame(19, $invoice->layout->layoutNumber);
        self::assertNotNull($invoice->project);
        self::assertSame(14, $invoice->project->projectNumber);
        self::assertNotNull($invoice->pdf);
        self::assertSame('https://restapi.e-conomic.com/invoices/booked/333/pdf', $invoice->pdf->download);

        // `sent` is a link field — deliberately untyped, raw only.
        self::assertSame('https://restapi.e-conomic.com/invoices/sent/333', $invoice->raw['sent']);

        self::assertCount(1, $invoice->lines);
        $line = $invoice->lines[0];
        self::assertSame(25.0, $line->vatRate);
        self::assertSame(180.0, $line->vatAmount);
        self::assertSame(720.0, $line->totalNetAmount);
        self::assertNotNull($line->deliveryDate);
        self::assertSame('2026-04-15T00:00:00+00:00', $line->deliveryDate->format('Y-m-d\TH:i:sP'));
    }
}
