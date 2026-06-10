<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Exception\ValidationException;
use Setono\Economic\Request\Customer\CustomerRequest;
use Setono\Economic\Request\Identifier;
use Setono\Economic\Response\Customer\Customer;
use Setono\Economic\TestDouble\ScriptedHttpClient;
use Webmozart\Assert\Assert;

#[CoversClass(CustomersEndpoint::class)]
#[CoversClass(ResourceEndpoint::class)]
#[CoversClass(Client::class)]
final class CustomersCreateTest extends TestCase
{
    private const string CREATE_URL = 'https://restapi.e-conomic.com/customers';

    #[Test]
    public function create_dispatches_a_post_with_the_expected_request_envelope(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"customerNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->customers()->create(self::minimalRequiredRequest());

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
            ->on(self::CREATE_URL, '{"customerNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->customers()->create(self::minimalRequiredRequest());

        $body = (string) $http->sentRequests[0]->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'name' => 'Acme',
            'currency' => 'DKK',
            'customerGroup' => ['customerGroupNumber' => 1],
            'vatZone' => ['vatZoneNumber' => 1],
            'paymentTerms' => ['paymentTermsNumber' => 1],
        ], $decoded, 'required-only payload must serialize to exactly the 5 required keys; no nulls leak through');
    }

    #[Test]
    public function create_omits_null_optional_fields(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"customerNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->customers()->create(self::minimalRequiredRequest());

        $body = (string) $http->sentRequests[0]->getBody();

        // Every optional CustomerRequest key must be absent — not present as `null`.
        $forbiddenKeys = [
            'customerNumber', 'barred', 'address', 'city', 'country', 'zip',
            'corporateIdentificationNumber', 'pNumber', 'creditLimit', 'ean', 'email',
            'layout', 'publicEntryNumber', 'telephoneAndFaxNumber', 'mobilePhone',
            'eInvoicingDisabledByDefault', 'vatNumber', 'website', 'salesPerson',
        ];
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
            ->on(self::CREATE_URL, '{"customerNumber":1}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $request = new CustomerRequest(
            name: 'Acme',
            currency: 'DKK',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
            address: 'Main 1',
            email: 'foo@example.com',
            layout: Identifier::layout(17),
            salesPerson: Identifier::employee(5),
        );

        $client->customers()->create($request);

        $body = (string) $http->sentRequests[0]->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('Main 1', $decoded['address']);
        self::assertSame('foo@example.com', $decoded['email']);
        self::assertSame(['layoutNumber' => 17], $decoded['layout']);
        self::assertSame(['employeeNumber' => 5], $decoded['salesPerson']);

        // Other optional fields still absent
        self::assertArrayNotHasKey('customerNumber', $decoded);
        self::assertArrayNotHasKey('barred', $decoded);
        self::assertArrayNotHasKey('vatNumber', $decoded);
    }

    #[Test]
    public function create_returns_the_customer_dto_with_typed_scalars_and_raw_populated(): void
    {
        $responseBody = json_encode([
            'customerNumber' => 1234,
            'name' => 'Acme',
            'currency' => 'DKK',
            'email' => 'foo@example.com',
            'address' => 'Main 1',
            'balance' => 0.0,
            'corporateIdentificationNumber' => '12345678',
            // Reference object — stays in $raw, not on a typed property
            'customerGroup' => ['customerGroupNumber' => 1, 'self' => 'https://example/customerGroups/1'],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, $responseBody)
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $customer = $client->customers()->create(self::minimalRequiredRequest());

        // Typed scalars are populated
        self::assertSame(1234, $customer->customerNumber);
        self::assertSame('Acme', $customer->name);
        self::assertSame('DKK', $customer->currency);
        self::assertSame('foo@example.com', $customer->email);
        self::assertSame('Main 1', $customer->address);
        self::assertSame(0.0, $customer->balance);
        self::assertSame('12345678', $customer->corporateIdentificationNumber);

        // Reference objects are NOT typed — reachable via $raw
        $customerGroup = $customer->raw['customerGroup'];
        Assert::isArray($customerGroup);
        self::assertSame(1, $customerGroup['customerGroupNumber']);
    }

    #[Test]
    public function create_missing_optional_response_fields_default_to_null(): void
    {
        // Schema-conformant minimal response — only customerNumber + name are present;
        // every other typed field on Customer should land at null.
        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, '{"customerNumber":1,"name":"Acme"}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $customer = $client->customers()->create(self::minimalRequiredRequest());

        self::assertSame(1, $customer->customerNumber);
        self::assertSame('Acme', $customer->name);
        self::assertNull($customer->balance);
        self::assertNull($customer->creditLimit);
        self::assertNull($customer->email);
    }

    #[Test]
    public function create_surfaces_422_as_validation_exception(): void
    {
        $validationDoc = json_encode([
            'errorCode' => 4400,
            'developerHint' => 'Validation failed',
            'errors' => [
                'customerGroup' => ['customerGroupNumber' => ['Customer group 1 does not exist']],
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on(self::CREATE_URL, new Response(422, ['Content-Type' => 'application/json'], $validationDoc))
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        try {
            $client->customers()->create(self::minimalRequiredRequest());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getResponse()->getStatusCode());
            self::assertSame(4400, $e->getErrorCode());
            self::assertSame(
                ['customerGroup' => ['customerGroupNumber' => ['Customer group 1 does not exist']]],
                $e->getValidationErrors(),
            );
        }
    }

    private static function minimalRequiredRequest(): CustomerRequest
    {
        return new CustomerRequest(
            name: 'Acme',
            currency: 'DKK',
            customerGroup: Identifier::customerGroup(1),
            vatZone: Identifier::vatZone(1),
            paymentTerms: Identifier::paymentTerms(1),
        );
    }
}
