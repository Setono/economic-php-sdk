<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint\Orders;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Client\Endpoint\ResourceEndpoint;
use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Order\DraftOrderRequest;
use Setono\Economic\Request\Order\Recipient;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(DraftOrdersEndpoint::class)]
#[CoversClass(ResourceEndpoint::class)]
final class DraftOrdersUpdateTest extends TestCase
{
    private const string UPDATE_URL = 'https://restapi.e-conomic.com/orders/drafts/9';

    #[Test]
    public function update_dispatches_a_put_to_the_draft_order_url(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::UPDATE_URL, '{"orderNumber":9}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->orders()->drafts()->update(9, self::minimalRequiredRequest());

        self::assertCount(1, $http->sentRequests);
        $sent = $http->sentRequests[0];

        self::assertSame('PUT', $sent->getMethod());
        self::assertSame(self::UPDATE_URL, (string) $sent->getUri());
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function update_serializes_payload_through_identifier_and_null_skipping_transformers(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::UPDATE_URL, '{"orderNumber":9}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->orders()->drafts()->update(9, self::minimalRequiredRequest());

        $body = (string) $http->sentRequests[0]->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'date' => '2026-06-10',
            'currency' => 'DKK',
            'layout' => ['layoutNumber' => 17],
            'paymentTerms' => ['paymentTermsNumber' => 3],
            'customer' => ['customerNumber' => 42],
            'recipient' => [
                'name' => 'Acme',
                'vatZone' => ['vatZoneNumber' => 2],
            ],
        ], $decoded, 'identifiers must serialize as {<field>Number: n} and null optionals must be absent');
    }

    #[Test]
    public function update_returns_the_order_dto_with_typed_fields_and_raw_populated(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::UPDATE_URL, '{"orderNumber":9,"currency":"DKK"}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $order = $client->orders()->drafts()->update(9, self::minimalRequiredRequest());

        self::assertSame(9, $order->orderNumber);
        self::assertSame('DKK', $order->raw['currency']);
    }

    /**
     * The read-modify-write flow for orders: GET a draft order rich in nested structures,
     * prefill via the inherited {@see DraftOrderRequest::fromResponse()}, mutate, PUT it back —
     * and assert the ENTIRE serialized body. Proves the Valinor raw→request mapping recurses
     * through nested Payloads (Recipient, Delivery, References, Line) and reconstructs every
     * Identifier from the server's own reference shapes.
     */
    #[Test]
    public function read_modify_write_round_trip_carries_over_every_modeled_field(): void
    {
        $url = 'https://restapi.e-conomic.com/orders/drafts/1';
        $getBody = json_encode([
            'orderNumber' => 1, // not on the request DTO — dropped
            'date' => '2026-06-01',
            'currency' => 'DKK',
            'exchangeRate' => 743.21,
            'dueDate' => '2026-07-01',
            'grossAmount' => 124.88, // server-computed — dropped
            'netAmount' => 99.9, // server-computed — dropped
            'customer' => ['customerNumber' => 42, 'self' => 'https://example/customers/42'],
            'layout' => ['layoutNumber' => 17, 'self' => 'https://example/layouts/17'],
            'paymentTerms' => [
                'paymentTermsNumber' => 3,
                'daysOfCredit' => 30,
                'name' => 'Net 30',
                'paymentTermsType' => 'net',
                'self' => 'https://example/pt/3',
            ],
            'recipient' => [
                'name' => 'Acme',
                'address' => 'Main 1',
                'zip' => '9000',
                'city' => 'Aalborg',
                'country' => 'Denmark',
                'ean' => '5790000123456',
                'mobilePhone' => '+45 22222222',
                'vatZone' => ['vatZoneNumber' => 2, 'self' => 'https://example/vz/2'],
                'attention' => ['customerContactNumber' => 9, 'self' => 'https://example/contacts/9'],
            ],
            'delivery' => [
                'address' => 'Dock 1',
                'zip' => '9000',
                'city' => 'Aalborg',
                'country' => 'Denmark',
                'deliveryTerms' => 'EXW',
                'deliveryDate' => '2026-06-15',
            ],
            'notes' => ['heading' => 'Hello', 'textLine1' => 'L1', 'textLine2' => 'L2'],
            'references' => [
                'salesPerson' => ['employeeNumber' => 5, 'self' => 'https://example/employees/5'],
                'customerContact' => ['customerContactNumber' => 9, 'self' => 'https://example/contacts/9'],
                'other' => 'PO-123',
            ],
            'lines' => [
                [
                    'lineNumber' => 1,
                    'sortKey' => 1,
                    'description' => 'Widget',
                    'unit' => ['unitNumber' => 1, 'name' => 'pcs'],
                    'product' => ['productNumber' => 'SKU-001', 'self' => 'https://example/products/SKU-001'],
                    'quantity' => 2.5,
                    'unitNetPrice' => 49.95,
                    'discountPercentage' => 2.5,
                    'unitCostPrice' => 20.5,
                    'marginInBaseCurrency' => 74.88, // server-computed — dropped
                    'marginPercentage' => 59.95, // server-computed — dropped
                ],
            ],
            'pdf' => ['download' => 'https://example/orders/drafts/1/pdf'], // HATEOAS — dropped
            'self' => 'https://example/orders/drafts/1',
        ], \JSON_THROW_ON_ERROR);

        // ScriptedHttpClient keys on the URI alone, so the GET and the PUT share the script.
        $http = new ScriptedHttpClient()->on($url, $getBody);
        $client = new Client('app', 'agreement', httpClient: $http);

        $order = $client->orders()->drafts()->getByNumber(1);
        self::assertNotNull($order);

        $request = DraftOrderRequest::fromResponse($order);
        $request->currency = 'EUR';
        $request->notes = null; // null = omitted from JSON = cleared server-side

        $client->orders()->drafts()->update(1, $request);

        self::assertCount(2, $http->sentRequests);
        self::assertSame('PUT', $http->sentRequests[1]->getMethod());

        $putBody = (string) $http->sentRequests[1]->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($putBody, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'date' => '2026-06-01',
            'currency' => 'EUR',
            'layout' => ['layoutNumber' => 17],
            'paymentTerms' => ['paymentTermsNumber' => 3],
            'customer' => ['customerNumber' => 42],
            'recipient' => [
                'name' => 'Acme',
                'vatZone' => ['vatZoneNumber' => 2],
                'address' => 'Main 1',
                'zip' => '9000',
                'city' => 'Aalborg',
                'country' => 'Denmark',
                'ean' => '5790000123456',
                'attention' => ['customerContactNumber' => 9],
                'mobilePhone' => '+45 22222222',
            ],
            'exchangeRate' => 743.21,
            'dueDate' => '2026-07-01',
            'delivery' => [
                'address' => 'Dock 1',
                'zip' => '9000',
                'city' => 'Aalborg',
                'country' => 'Denmark',
                'deliveryTerms' => 'EXW',
                'deliveryDate' => '2026-06-15',
            ],
            // notes was set to null after prefill → stripped → cleared server-side
            'references' => [
                'salesPerson' => ['employeeNumber' => 5],
                'customerContact' => ['customerContactNumber' => 9],
                'other' => 'PO-123',
            ],
            'lines' => [
                [
                    'lineNumber' => 1,
                    'sortKey' => 1,
                    'description' => 'Widget',
                    'unit' => ['unitNumber' => 1],
                    'product' => ['productNumber' => 'SKU-001'],
                    'quantity' => 2.5,
                    'unitNetPrice' => 49.95,
                    'discountPercentage' => 2.5,
                    'unitCostPrice' => 20.5,
                ],
            ],
        ], $decoded, 'the PUT body must carry over every modeled field (including nested Payloads and Identifiers), apply the mutation, omit the nulled notes block, and drop server-computed fields');
    }

    private static function minimalRequiredRequest(): DraftOrderRequest
    {
        return new DraftOrderRequest(
            date: '2026-06-10',
            currency: 'DKK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(3),
            customer: Identifier::customer(42),
            recipient: new Recipient(
                name: 'Acme',
                vatZone: Identifier::vatZone(2),
            ),
        );
    }
}
