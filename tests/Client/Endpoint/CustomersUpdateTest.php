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

#[CoversClass(CustomersEndpoint::class)]
#[CoversClass(ResourceEndpoint::class)]
#[CoversClass(Client::class)]
#[CoversClass(CustomerRequest::class)]
final class CustomersUpdateTest extends TestCase
{
    private const string UPDATE_URL = 'https://restapi.e-conomic.com/customers/1234';

    #[Test]
    public function update_dispatches_a_put_with_the_expected_request_envelope(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::UPDATE_URL, '{"customerNumber":1234}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->customers()->update(1234, self::minimalRequiredRequest());

        self::assertCount(1, $http->sentRequests);
        $sent = $http->sentRequests[0];

        self::assertSame('PUT', $sent->getMethod());
        self::assertSame(self::UPDATE_URL, (string) $sent->getUri());
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame('app', $sent->getHeaderLine('X-AppSecretToken'));
        self::assertSame('agreement', $sent->getHeaderLine('X-AgreementGrantToken'));
        self::assertNotSame('', $sent->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function update_serializes_required_only_payload_with_identifiers_keyed_correctly(): void
    {
        $http = new ScriptedHttpClient()
            ->on(self::UPDATE_URL, '{"customerNumber":1234}')
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $client->customers()->update(1234, self::minimalRequiredRequest());

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
    public function update_returns_the_customer_dto_with_typed_scalars_and_raw_populated(): void
    {
        $responseBody = json_encode([
            'customerNumber' => 1234,
            'name' => 'Acme',
            'currency' => 'DKK',
            'telephoneAndFaxNumber' => '+45 11111111',
            'mobilePhone' => '+45 22222222',
            'lastUpdated' => '2020-02-19T09:18:09Z',
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on(self::UPDATE_URL, $responseBody)
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        $customer = $client->customers()->update(1234, self::minimalRequiredRequest());

        self::assertSame(1234, $customer->customerNumber);
        self::assertSame('Acme', $customer->name);
        self::assertSame('+45 11111111', $customer->telephoneAndFaxNumber);
        self::assertSame('+45 22222222', $customer->mobilePhone);
        self::assertNotNull($customer->lastUpdated);
        self::assertSame('2020-02-19T09:18:09+00:00', $customer->lastUpdated->format(\DateTimeInterface::RFC3339));
        self::assertSame('Acme', $customer->raw['name']);
    }

    #[Test]
    public function update_surfaces_422_as_validation_exception(): void
    {
        $validationDoc = json_encode([
            'errorCode' => 4400,
            'developerHint' => 'Validation failed',
            'errors' => [
                'customerGroup' => ['customerGroupNumber' => ['Customer group 1 does not exist']],
            ],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on(self::UPDATE_URL, new Response(422, ['Content-Type' => 'application/json'], $validationDoc))
        ;
        $client = new Client('app', 'agreement', httpClient: $http);

        try {
            $client->customers()->update(1234, self::minimalRequiredRequest());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getResponse()->getStatusCode());
            self::assertSame(4400, $e->getErrorCode());
        }
    }

    /**
     * The documented read-modify-write flow end to end: GET a customer rich in reference
     * objects and untyped scalars, prefill a request via {@see CustomerRequest::fromResponse()},
     * mutate two fields, PUT it back — and assert the ENTIRE serialized body, proving every
     * carry-over (typed and `$raw`-sourced), the mutation, and the null-clears-field semantics.
     */
    #[Test]
    public function read_modify_write_round_trip_carries_over_every_modeled_field(): void
    {
        $url = 'https://restapi.e-conomic.com/customers/1';
        $getBody = json_encode([
            'customerNumber' => 1,
            'name' => 'Acme',
            'currency' => 'DKK',
            'barred' => false,
            'lastUpdated' => '2020-02-19T09:18:09Z',
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
        ], \JSON_THROW_ON_ERROR);

        // ScriptedHttpClient keys on the URI alone, so the GET and the PUT share the script.
        $http = new ScriptedHttpClient()->on($url, $getBody);
        $client = new Client('app', 'agreement', httpClient: $http);

        $customer = $client->customers()->getByNumber(1);
        self::assertInstanceOf(Customer::class, $customer);

        $request = CustomerRequest::fromResponse($customer);
        $request->name = 'Renamed';
        $request->mobilePhone = null;

        $client->customers()->update(1, $request);

        self::assertCount(2, $http->sentRequests);
        self::assertSame('PUT', $http->sentRequests[1]->getMethod());

        $putBody = (string) $http->sentRequests[1]->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($putBody, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'name' => 'Renamed',
            'currency' => 'DKK',
            'customerGroup' => ['customerGroupNumber' => 1],
            'vatZone' => ['vatZoneNumber' => 2],
            'paymentTerms' => ['paymentTermsNumber' => 3],
            'customerNumber' => 1,
            'barred' => false,
            'address' => 'Main 1',
            'city' => 'Aalborg',
            'country' => 'Denmark',
            'zip' => '9000',
            'corporateIdentificationNumber' => '12345678',
            'pNumber' => '1007331700',
            'creditLimit' => 1000.5,
            'ean' => '5790000123456',
            'email' => 'foo@example.com',
            'layout' => ['layoutNumber' => 17],
            'publicEntryNumber' => 'PEN-1',
            'telephoneAndFaxNumber' => '+45 11111111',
            // mobilePhone was set to null after prefill → stripped → cleared server-side
            'eInvoicingDisabledByDefault' => true,
            'vatNumber' => 'DK12345678',
            'website' => 'https://acme.example.com',
            'salesPerson' => ['employeeNumber' => 5],
        ], $decoded, 'the PUT body must carry over every modeled field, apply the mutation, and omit the nulled field; server-computed fields (balance, dueAmount, lastUpdated) must be absent');
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
