<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use CuyZ\Valinor\MapperBuilder;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18Client;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Setono\Economic\Client\Endpoint\InvoicesEndpoint;
use Setono\Economic\Client\Endpoint\InvoicesEndpointInterface;
use Setono\Economic\Client\Endpoint\OrdersEndpoint;
use Setono\Economic\Client\Endpoint\OrdersEndpointInterface;
use Setono\Economic\Client\Endpoint\ProductsEndpoint;
use Setono\Economic\Client\Endpoint\ProductsEndpointInterface;
use Setono\Economic\Client\Query\Query;
use Setono\Economic\Exception\InternalServerErrorException;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Exception\UnexpectedStatusCodeException;

final class Client implements ClientInterface, LoggerAwareInterface
{
    private ?RequestInterface $lastRequest = null;

    private ?ResponseInterface $lastResponse = null;

    private ?InvoicesEndpointInterface $invoicesEndpoint = null;

    private ?OrdersEndpointInterface $ordersEndpoint = null;

    private ?ProductsEndpointInterface $productsEndpoint = null;

    private ?HttpClientInterface $httpClient = null;

    private ?RequestFactoryInterface $requestFactory = null;

    private LoggerInterface $logger;

    private ?MapperBuilder $mapperBuilder = null;

    public function __construct(private readonly string $appSecretToken, private readonly string $agreementGrantToken)
    {
        $this->logger = new NullLogger();
    }

    public function getLastRequest(): ?RequestInterface
    {
        return $this->lastRequest;
    }

    public function getLastResponse(): ?ResponseInterface
    {
        return $this->lastResponse;
    }

    public function request(RequestInterface $request): ResponseInterface
    {
        $request = $request->withHeader('X-AppSecretToken', $this->appSecretToken)
            ->withHeader('X-AgreementGrantToken', $this->agreementGrantToken)
            ->withHeader('Content-Type', 'application/json')
        ;

        $this->lastRequest = $request;
        $this->lastResponse = $this->getHttpClient()->sendRequest($this->lastRequest);

        self::assertStatusCode($this->lastResponse);

        return $this->lastResponse;
    }

    public function get(string $uri, Query|array $query = []): ResponseInterface
    {
        if (is_array($query)) {
            $query = new Query($query);
        }

        $url = sprintf(
            '%s/%s%s',
            $this->getBaseUri(),
            ltrim($uri, '/'),
            $query->isEmpty() ? '' : '?' . $query->toString(),
        );

        $request = $this->getRequestFactory()->createRequest('GET', $url);

        return $this->request($request);
    }

    public function invoices(): InvoicesEndpointInterface
    {
        if (null === $this->invoicesEndpoint) {
            $this->invoicesEndpoint = new InvoicesEndpoint($this, $this->getMapperBuilder());
            $this->invoicesEndpoint->setLogger($this->logger);
        }

        return $this->invoicesEndpoint;
    }

    public function orders(): OrdersEndpointInterface
    {
        if (null === $this->ordersEndpoint) {
            $this->ordersEndpoint = new OrdersEndpoint($this, $this->getMapperBuilder());
            $this->ordersEndpoint->setLogger($this->logger);
        }

        return $this->ordersEndpoint;
    }

    public function products(): ProductsEndpointInterface
    {
        if (null === $this->productsEndpoint) {
            $this->productsEndpoint = new ProductsEndpoint($this, $this->getMapperBuilder());
            $this->productsEndpoint->setLogger($this->logger);
        }

        return $this->productsEndpoint;
    }

    public function setMapperBuilder(MapperBuilder $mapperBuilder): void
    {
        $this->mapperBuilder = $mapperBuilder;
    }

    public function getMapperBuilder(): MapperBuilder
    {
        if (null === $this->mapperBuilder) {
            $this->mapperBuilder = (new MapperBuilder())
                ->enableFlexibleCasting()
                ->allowSuperfluousKeys()
            ;
        }

        return $this->mapperBuilder;
    }

    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    public function setRequestFactory(?RequestFactoryInterface $requestFactory): void
    {
        $this->requestFactory = $requestFactory;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    private function getBaseUri(): string
    {
        return 'https://restapi.e-conomic.com';
    }

    private function getHttpClient(): HttpClientInterface
    {
        if (null === $this->httpClient) {
            $this->httpClient = new Psr18Client();
        }

        return $this->httpClient;
    }

    private function getRequestFactory(): RequestFactoryInterface
    {
        if (null === $this->requestFactory) {
            $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        }

        return $this->requestFactory;
    }

    private static function assertStatusCode(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        NotFoundException::assert($response);
        InternalServerErrorException::assert($response);

        throw new UnexpectedStatusCodeException($response);
    }
}
