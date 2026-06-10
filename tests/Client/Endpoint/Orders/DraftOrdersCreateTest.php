<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint\Orders;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Exception\ValidationException;
use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Order\Accrual;
use Setono\Economic\Request\Order\DraftOrderRequest;
use Setono\Economic\Request\Order\Line;
use Setono\Economic\Request\Order\Notes;
use Setono\Economic\Request\Order\Recipient;
use Setono\Economic\Response\Order\Order;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(DraftOrdersEndpoint::class)]
#[CoversClass(Client::class)]
final class DraftOrdersCreateTest extends TestCase
{
    private const string CREATE_URL = 'https://restapi.e-conomic.com/orders/drafts';

    #[Test]
    public function create_dispatches_a_post_with_the_expected_request_envelope(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"orderNumber":1234}')
        ;

        $client = new Client('app', 'agreement', httpClient: $http);

        $client->orders()->drafts()->create(self::minimalRequiredRequest());

        self::assertCount(1, $http->sentRequests);
        $sent = $http->sentRequests[0];

        self::assertSame('POST', $sent->getMethod());
        self::assertSame(self::CREATE_URL, (string) $sent->getUri());
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame('app', $sent->getHeaderLine('X-AppSecretToken'));
        self::assertSame('agreement', $sent->getHeaderLine('X-AgreementGrantToken'));
        self::assertNotSame('', $sent->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function create_serializes_required_only_payload_with_identifiers_keyed_correctly(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"orderNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->orders()->drafts()->create(self::minimalRequiredRequest());

        $body = (string) $http->sentRequests[0]->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'date' => '2026-05-27',
            'currency' => 'DKK',
            'layout' => ['layoutNumber' => 17],
            'paymentTerms' => ['paymentTermsNumber' => 1],
            'customer' => ['customerNumber' => 1],
            'recipient' => [
                'name' => 'Foo',
                'vatZone' => ['vatZoneNumber' => 1],
            ],
        ], $decoded, 'required-only payload must serialize to exactly the 6 required keys; no nulls leak through');
    }

    #[Test]
    public function create_omits_null_optional_fields(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"orderNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->orders()->drafts()->create(self::minimalRequiredRequest());

        $body = (string) $http->sentRequests[0]->getBody();

        // Every optional DraftOrderRequest key must be absent — not present as `null`.
        $forbiddenKeys = ['exchangeRate', 'dueDate', 'project', 'deliveryLocation', 'delivery', 'notes', 'references', 'lines'];
        foreach ($forbiddenKeys as $key) {
            self::assertStringNotContainsString(
                sprintf('"%s"', $key),
                $body,
                sprintf('Null optional field "%s" must be absent from the body, not serialized as null', $key),
            );
        }
    }

    #[Test]
    public function create_serializes_supplied_optional_fields_alongside_required_ones(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"orderNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $request = new DraftOrderRequest(
            date: '2026-05-27',
            currency: 'DKK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
            notes: new Notes(heading: 'Greetings'),
            lines: [
                new Line(description: 'First line', quantity: 2.5, unitNetPrice: 49.95, product: Identifier::product('SKU-001')),
            ],
        );

        $client->orders()->drafts()->create($request);

        $body = (string) $http->sentRequests[0]->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        // notes is present with just its heading; the two null fields on Notes itself were dropped
        self::assertSame(['heading' => 'Greetings'], $decoded['notes']);

        // lines is an array of one item; the line's nulls (lineNumber, sortKey, accrual, ...) are dropped
        self::assertIsArray($decoded['lines']);
        self::assertCount(1, $decoded['lines']);
        self::assertSame([
            'description' => 'First line',
            'product' => ['productNumber' => 'SKU-001'],
            'quantity' => 2.5,
            'unitNetPrice' => 49.95,
        ], $decoded['lines'][0]);
    }

    #[Test]
    public function payload_null_skipping_applies_recursively_to_nested_payload_dtos(): void
    {
        // Deeply nested Payload graph: DraftOrderRequest -> Line -> Accrual (all-null).
        // The Payload transformer fires per-instance during Valinor's recursive normalization,
        // so the Accrual whose fields are all null should normalize to [] which is then
        // stripped from the parent Line's output.
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"orderNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $request = new DraftOrderRequest(
            date: '2026-05-27',
            currency: 'DKK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
            lines: [
                new Line(
                    description: 'Widget',
                    accrual: new Accrual(), // all-null nested Payload
                ),
            ],
        );

        $client->orders()->drafts()->create($request);

        $body = (string) $http->sentRequests[0]->getBody();

        // The Line contains a description; accrual is present in PHP but its fields are all null.
        // After recursive null-skipping the Accrual normalizes to [], which is then stripped from
        // the Line's output entirely. Body should NOT mention "accrual" anywhere.
        self::assertStringNotContainsString(
            '"accrual"',
            $body,
            'all-null nested Payload (Accrual) must be stripped recursively, not serialized as empty object',
        );
    }

    #[Test]
    public function create_returns_the_order_dto_with_raw_populated(): void
    {
        $responseBody = '{"orderNumber":1234,"grossAmount":125.0,"netAmount":100.0,"vatAmount":25.0}';
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, $responseBody)
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $order = $client->orders()->drafts()->create(self::minimalRequiredRequest());

        // $order is statically typed Order — assertInstanceOf would be tautological;
        // verify the typed fields and the $raw envelope instead.
        self::assertSame(1234, $order->orderNumber);
        self::assertSame(1234, $order->raw['orderNumber']);
        // server-computed fields land in $raw — the SDK doesn't (yet) type them on Order
        self::assertSame(125.0, $order->raw['grossAmount']);
        self::assertSame(100.0, $order->raw['netAmount']);
    }

    #[Test]
    public function create_surfaces_422_as_validation_exception_with_server_validation_document(): void
    {
        $validationDoc = json_encode([
            'errorCode' => 4400,
            'developerHint' => 'Validation failed',
            // e-conomic nests the validation document under "errors", not "validationErrors".
            'errors' => [
                'customer' => ['customerNumber' => ['Customer 1 does not exist']],
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, new Response(422, ['Content-Type' => 'application/json'], $validationDoc))
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        try {
            $client->orders()->drafts()->create(self::minimalRequiredRequest());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getResponse()->getStatusCode());
            self::assertSame(4400, $e->getErrorCode());
            self::assertSame('Validation failed', $e->getDeveloperHint());
            self::assertSame(
                ['customer' => ['customerNumber' => ['Customer 1 does not exist']]],
                $e->getValidationErrors(),
            );
        }
    }

    private static function minimalRequiredRequest(): DraftOrderRequest
    {
        return new DraftOrderRequest(
            date: '2026-05-27',
            currency: 'DKK',
            layout: Identifier::layout(17),
            paymentTerms: Identifier::paymentTerms(1),
            customer: Identifier::customer(1),
            recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
        );
    }
}
