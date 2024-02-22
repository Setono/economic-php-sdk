<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use CuyZ\Valinor\MapperBuilder;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \Setono\Economic\Client\Client
 */
final class ClientTest extends TestCase
{
    /**
     * @test
     */
    public function it_sends_expected_request(): void
    {
        $httpClient = new MockHttpClient();

        $client = self::createClient();
        $client->setHttpClient($httpClient);
        $client->get('/endpoint/sub', [
            'empty' => null,
            'param1' => 'value 1',
            'param2' => 'value 2',
        ]);

        self::assertNotNull($httpClient->lastRequest);
        self::assertNotNull($client->getLastResponse());
        self::assertNotNull($client->getLastRequest());
        self::assertSame('GET', $httpClient->lastRequest->getMethod());
        self::assertSame(
            'https://restapi.e-conomic.com/endpoint/sub?param1=value%201&param2=value%202',
            (string) $httpClient->lastRequest->getUri(),
        );
        self::assertSame('app-secret-token', $httpClient->lastRequest->getHeaderLine('X-AppSecretToken'));
        self::assertSame('agreement-grant-token', $httpClient->lastRequest->getHeaderLine('X-AgreementGrantToken'));
    }

    /**
     * @test
     */
    public function it_returns_same_invoices_endpoint(): void
    {
        $client = self::createClient();
        $endpoint = $client->invoices();

        // this checks that we get the same instance for each call
        self::assertSame($endpoint, $client->invoices());
    }

    /**
     * @test
     */
    public function it_returns_same_products_endpoint(): void
    {
        $client = self::createClient();
        $endpoint = $client->products();

        // this checks that we get the same instance for each call
        self::assertSame($endpoint, $client->products());
    }

    /**
     * @test
     */
    public function it_returns_mapper_builder(): void
    {
        $client = self::createClient();

        $mapperBuilder = $client->getMapperBuilder();

        self::assertSame($mapperBuilder, $client->getMapperBuilder());
    }

    /**
     * @test
     */
    public function it_allows_to_set_the_mapper_builder(): void
    {
        $client = self::createClient();

        $mapperBuilder = $client->getMapperBuilder();
        $client->setMapperBuilder(new MapperBuilder());

        self::assertNotSame($mapperBuilder, $client->getMapperBuilder());
    }

    private static function createClient(): Client
    {
        return new Client('app-secret-token', 'agreement-grant-token');
    }
}

final class MockHttpClient implements HttpClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return new Response();
    }
}
