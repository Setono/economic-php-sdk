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
