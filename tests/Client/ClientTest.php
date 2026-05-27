<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use CuyZ\Valinor\MapperBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\Economic\Exception\InvalidUrlException;
use Setono\Economic\Exception\MalformedResponseException;

#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    #[Test]
    public function it_sends_expected_request(): void
    {
        $httpClient = new MockHttpClient();

        $client = self::createClient($httpClient);
        $client->get('/endpoint/sub', [
            'empty' => null,
            'param1' => 'value 1',
            'param2' => 'value 2',
        ]);

        self::assertNotNull($httpClient->lastRequest);
        self::assertNotNull($client->lastResponse);
        self::assertNotNull($client->lastRequest);
        self::assertSame('GET', $httpClient->lastRequest->getMethod());
        self::assertSame(
            'https://restapi.e-conomic.com/endpoint/sub?param1=value%201&param2=value%202',
            (string) $httpClient->lastRequest->getUri(),
        );
        self::assertSame('app-secret-token', $httpClient->lastRequest->getHeaderLine('X-AppSecretToken'));
        self::assertSame('agreement-grant-token', $httpClient->lastRequest->getHeaderLine('X-AgreementGrantToken'));

        // User-Agent identifies the SDK and includes a version
        self::assertMatchesRegularExpression(
            '#^Setono-Economic-PHP/[^ ]+ \(\+https://github.com/Setono/economic-php-sdk\)$#',
            $httpClient->lastRequest->getHeaderLine('User-Agent'),
        );
    }

    #[Test]
    public function get_with_empty_query_appends_no_question_mark(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        $client->get('products');

        self::assertNotNull($httpClient->lastRequest);
        self::assertSame('https://restapi.e-conomic.com/products', (string) $httpClient->lastRequest->getUri());
    }

    #[Test]
    public function get_with_absolute_url_does_not_prepend_base_uri(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        $absolute = 'https://restapi.e-conomic.com/products?skippages=2&pagesize=20';
        $client->get($absolute);

        self::assertNotNull($httpClient->lastRequest);
        self::assertSame($absolute, (string) $httpClient->lastRequest->getUri());
        // auth + UA still applied
        self::assertSame('app-secret-token', $httpClient->lastRequest->getHeaderLine('X-AppSecretToken'));
        self::assertNotSame('', $httpClient->lastRequest->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function pristine_client_journals_null(): void
    {
        $client = self::createClient();

        self::assertNull($client->lastRequest);
        self::assertNull($client->lastResponse);
    }

    #[Test]
    public function get_refuses_absolute_url_with_foreign_host(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        $this->expectException(InvalidUrlException::class);

        $client->get('https://attacker.example.com/products');
    }

    #[Test]
    public function get_does_not_leak_credentials_on_foreign_host(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        try {
            $client->get('https://attacker.example.com/products');
        } catch (InvalidUrlException) {
            // expected
        }

        self::assertNull($httpClient->lastRequest, 'no request must be dispatched to the foreign host');
    }

    #[Test]
    public function get_rejects_absolute_url_combined_with_query(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        $this->expectException(InvalidUrlException::class);

        $client->get(
            'https://restapi.e-conomic.com/products?skippages=0',
            ['extra' => 'param'],
        );
    }

    #[Test]
    public function get_rejects_relative_uri_with_embedded_query_combined_with_query_array(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        $this->expectException(InvalidUrlException::class);

        $client->get('products?embed=lines', ['pagesize' => 20]);
    }

    #[Test]
    public function get_allows_relative_uri_with_embedded_query_when_query_array_is_empty(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        $client->get('products?embed=lines');

        self::assertNotNull($httpClient->lastRequest);
        self::assertSame(
            'https://restapi.e-conomic.com/products?embed=lines',
            (string) $httpClient->lastRequest->getUri(),
        );
    }

    #[Test]
    public function get_throws_malformed_response_exception_when_body_is_not_json(): void
    {
        $http = new class() implements HttpClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], 'this is not json');
            }
        };
        $client = self::createClient($http);

        try {
            $client->get('products');
            self::fail('expected exception');
        } catch (MalformedResponseException $e) {
            // carries the response (inherited from ResponseAwareException)
            self::assertSame(200, $e->getResponse()->getStatusCode());
            // lazy-parse getters degrade gracefully on a garbage body
            self::assertNull($e->getErrorCode());
            self::assertSame([], $e->getValidationErrors());
        }
    }

    #[Test]
    public function get_throws_malformed_response_exception_when_body_is_not_an_object(): void
    {
        $http = new class() implements HttpClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '"a string, not an object"');
            }
        };
        $client = self::createClient($http);

        $this->expectException(MalformedResponseException::class);
        $client->get('products');
    }

    #[Test]
    public function it_returns_same_invoices_endpoint(): void
    {
        $client = self::createClient();
        $endpoint = $client->invoices();

        self::assertSame($endpoint, $client->invoices());
    }

    #[Test]
    public function invoices_booked_returns_same_sub_endpoint(): void
    {
        $client = self::createClient();
        $booked = $client->invoices()->booked();

        self::assertSame($booked, $client->invoices()->booked());
    }

    #[Test]
    public function it_returns_same_orders_endpoint(): void
    {
        $client = self::createClient();
        $endpoint = $client->orders();

        self::assertSame($endpoint, $client->orders());
    }

    #[Test]
    public function orders_drafts_returns_same_sub_endpoint(): void
    {
        $client = self::createClient();
        $drafts = $client->orders()->drafts();

        self::assertSame($drafts, $client->orders()->drafts());
    }

    #[Test]
    public function orders_sent_returns_same_sub_endpoint(): void
    {
        $client = self::createClient();
        $sent = $client->orders()->sent();

        self::assertSame($sent, $client->orders()->sent());
    }

    #[Test]
    public function it_returns_same_products_endpoint(): void
    {
        $client = self::createClient();
        $endpoint = $client->products();

        self::assertSame($endpoint, $client->products());
    }

    #[Test]
    public function it_returns_same_self_endpoint(): void
    {
        $client = self::createClient();
        $endpoint = $client->self();

        self::assertSame($endpoint, $client->self());
    }

    #[Test]
    public function client_self_does_not_trigger_http(): void
    {
        $httpClient = new MockHttpClient();
        $client = self::createClient($httpClient);

        $client->self();

        self::assertNull($httpClient->lastRequest, 'self() accessor must not issue a request');
    }

    #[Test]
    public function consumer_supplied_mapper_builder_is_the_one_endpoints_receive(): void
    {
        // Consumers inject a pre-configured (typically cached) MapperBuilder via the constructor.
        // It MUST be the exact instance that every endpoint gets — no fresh default builder.
        $custom = new MapperBuilder();
        $client = new Client('app-secret-token', 'agreement-grant-token', mapperBuilder: $custom);

        $endpoints = [
            'invoices' => $client->invoices(),
            'orders' => $client->orders(),
            'products' => $client->products(),
            'self' => $client->self(),
        ];

        foreach ($endpoints as $name => $endpoint) {
            $prop = new \ReflectionProperty($endpoint, 'mapperBuilder');
            self::assertSame(
                $custom,
                $prop->getValue($endpoint),
                sprintf('%s endpoint did not receive the constructor-injected MapperBuilder', $name),
            );
        }
    }

    #[Test]
    public function zero_config_construction_resolves_collaborators_via_discovery(): void
    {
        // No collaborators passed — Psr18ClientDiscovery / Psr17FactoryDiscovery must resolve them.
        // nyholm/psr7 and symfony/http-client are dev-deps, so discovery has implementations to find.
        $client = new Client('app-secret-token', 'agreement-grant-token');

        $reflection = new \ReflectionClass($client);

        foreach (['httpClient', 'requestFactory', 'streamFactory', 'mapperBuilder'] as $property) {
            $prop = $reflection->getProperty($property);
            self::assertNotNull(
                $prop->getValue($client),
                sprintf('Client::$%s must be resolved (non-null) after construction with no explicit collaborator', $property),
            );
        }
    }

    #[Test]
    public function consumer_supplied_stream_factory_is_stored_on_the_client(): void
    {
        $custom = new Psr17Factory();
        $client = new Client('app-secret-token', 'agreement-grant-token', streamFactory: $custom);

        self::assertSame(
            $custom,
            $client->getStreamFactory(),
            'Client must retain the consumer-supplied StreamFactoryInterface — the BYOHC contract '
            . 'pre-wires this collaborator for future write endpoints, so it must not be discarded.',
        );
    }

    #[Test]
    public function client_exposes_no_collaborator_setters(): void
    {
        // Locks the immutability requirement: once constructed, collaborators cannot be swapped.
        $reflection = new \ReflectionClass(Client::class);

        foreach (['setHttpClient', 'setRequestFactory', 'setStreamFactory', 'setMapperBuilder'] as $forbidden) {
            self::assertFalse(
                $reflection->hasMethod($forbidden),
                sprintf('Client must not expose %s() — collaborators are constructor-injected only', $forbidden),
            );
        }
    }

    private static function createClient(?HttpClientInterface $httpClient = null): Client
    {
        return new Client('app-secret-token', 'agreement-grant-token', httpClient: $httpClient);
    }
}

final class MockHttpClient implements HttpClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        // Empty JSON object — Client::get/getUrl decodes the body, so it must be valid JSON.
        return new Response(200, ['Content-Type' => 'application/json'], '{}');
    }
}
