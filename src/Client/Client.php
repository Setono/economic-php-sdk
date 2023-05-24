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
use Setono\Economic\Client\Endpoint\ProductsEndpoint;
use Setono\Economic\Client\Endpoint\ProductsEndpointInterface;
use Setono\Economic\Exception\InternalServerErrorException;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Exception\UnexpectedStatusCodeException;

final class Client implements ClientInterface, LoggerAwareInterface
{
    private ?RequestInterface $lastRequest = null;

    private ?ResponseInterface $lastResponse = null;

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

    public function get(string $uri, array $query = []): ResponseInterface
    {
        $q = http_build_query(array_map(static function ($element) {
            return $element instanceof \DateTimeInterface ? $element->format(\DATE_ATOM) : $element;
        }, $query), '', '&', \PHP_QUERY_RFC3986);

        $url = sprintf('%s/%s%s', $this->getBaseUri(), ltrim($uri, '/'), '' === $q ? '' : '?' . $q);

        $request = $this->getRequestFactory()->createRequest('GET', $url);

        return $this->request($request);
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
