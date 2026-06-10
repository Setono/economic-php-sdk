<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Exception\MappingException;
use Setono\Economic\Response\Customer\Customer;
use Setono\Economic\TestDouble\ScriptedHttpClient;
use Webmozart\Assert\Assert;

#[CoversClass(CustomersEndpoint::class)]
final class CustomersLookupTest extends TestCase
{
    #[Test]
    public function customers_get_by_number_hits_correct_url_and_returns_typed_customer(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/customers/1',
                '{"customerNumber":1,"name":"Acme","currency":"DKK","balance":42.5}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);

        $customer = $client->customers()->getByNumber(1);

        self::assertNotNull($customer);
        self::assertSame(1, $customer->customerNumber);
        self::assertSame('Acme', $customer->name);
        self::assertSame('DKK', $customer->currency);
        self::assertSame(42.5, $customer->balance);
    }

    #[Test]
    public function customers_get_by_number_returns_null_on_404(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/customers/999', new Response(404))
        ;

        $client = new Client('app', 'agreement', httpClient: $http);

        self::assertNull($client->customers()->getByNumber(999));
    }

    #[Test]
    public function customers_get_by_number_keeps_typed_fields_in_raw_and_leaves_link_blobs_untyped(): void
    {
        // Reference objects (customerGroup, paymentTerms, …) are typed on the Customer DTO,
        // but $raw still carries their original array slices. HATEOAS link blobs (invoices,
        // contacts, …) remain raw-only.
        $body = json_encode([
            'customerNumber' => 1,
            'name' => 'Acme',
            'customerGroup' => ['customerGroupNumber' => 7, 'self' => 'https://example/cg/7'],
            'paymentTerms' => ['paymentTermsNumber' => 2],
            'invoices' => ['drafts' => 'https://example/invoices/drafts'],
        ], \JSON_THROW_ON_ERROR);

        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/customers/1', $body)
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $customer = $client->customers()->getByNumber(1);

        self::assertInstanceOf(Customer::class, $customer);

        $customerGroup = $customer->raw['customerGroup'];
        $paymentTerms = $customer->raw['paymentTerms'];
        $invoices = $customer->raw['invoices'];
        Assert::isArray($customerGroup);
        Assert::isArray($paymentTerms);
        Assert::isArray($invoices);

        self::assertSame(7, $customerGroup['customerGroupNumber']);
        self::assertSame(2, $paymentTerms['paymentTermsNumber']);
        self::assertSame('https://example/invoices/drafts', $invoices['drafts']);
    }

    #[Test]
    public function phone_fields_map_to_typed_properties(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/customers/1',
                '{"customerNumber":1,"telephoneAndFaxNumber":"+45 11111111","mobilePhone":"+45 22222222"}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $customer = $client->customers()->getByNumber(1);

        self::assertNotNull($customer);
        self::assertSame('+45 11111111', $customer->telephoneAndFaxNumber);
        self::assertSame('+45 22222222', $customer->mobilePhone);
    }

    #[Test]
    public function last_updated_maps_to_date_time_immutable_and_raw_keeps_the_wire_string(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/customers/1',
                '{"customerNumber":1,"lastUpdated":"2020-02-19T09:18:09Z"}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $customer = $client->customers()->getByNumber(1);

        self::assertNotNull($customer);
        self::assertNotNull($customer->lastUpdated);
        self::assertSame('2020-02-19T09:18:09+00:00', $customer->lastUpdated->format(\DateTimeInterface::RFC3339));
        self::assertSame('2020-02-19T09:18:09Z', $customer->raw['lastUpdated']);
    }

    #[Test]
    public function absent_last_updated_maps_to_null(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/customers/1', '{"customerNumber":1}')
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $customer = $client->customers()->getByNumber(1);

        self::assertNotNull($customer);
        self::assertNull($customer->lastUpdated);
    }

    #[Test]
    public function garbage_last_updated_surfaces_as_mapping_exception(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/customers/1',
                '{"customerNumber":1,"lastUpdated":"not-a-date"}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);

        $this->expectException(MappingException::class);

        $client->customers()->getByNumber(1);
    }

    #[Test]
    public function valinor_mapping_failure_surfaces_as_mapping_exception_implementing_economic_exception(): void
    {
        // 2xx body that decodes as JSON but doesn't fit the Customer DTO shape — customerNumber
        // is typed `?int`, sending a non-coercible object value forces Valinor to raise
        // MappingError. The SDK wraps it as MappingException so `catch (EconomicException)`
        // and `catch (MalformedResponseException)` both net the failure.
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/customers/1',
                '{"customerNumber":{"nested":"object"}}',
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);

        try {
            $client->customers()->getByNumber(1);
            self::fail('expected MappingException');
        } catch (MappingException $e) {
            self::assertStringContainsString(
                \Setono\Economic\Response\Customer\Customer::class,
                $e->getMessage(),
                'message must name the target DTO class',
            );
            self::assertStringContainsString(
                '[GET https://restapi.e-conomic.com/customers/1]',
                $e->getMessage(),
                'message must include the HTTP method/URL context',
            );
            // Hierarchy is enforced statically (MappingException extends MalformedResponseException)
            // — the dual catch below also proves the relationship at runtime.
            self::assertNotNull($e->getPrevious(), 'the original Valinor MappingError must be preserved as $previous');
        }
    }
}
