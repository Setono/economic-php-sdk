<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use Composer\InstalledVersions;
use CuyZ\Valinor\MapperBuilder;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18Client;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\Economic\Client\Endpoint\InvoicesEndpoint;
use Setono\Economic\Client\Endpoint\OrdersEndpoint;
use Setono\Economic\Client\Endpoint\ProductsEndpoint;
use Setono\Economic\Client\Endpoint\SelfEndpoint;
use Setono\Economic\Exception\ForbiddenException;
use Setono\Economic\Exception\InternalServerErrorException;
use Setono\Economic\Exception\InvalidUrlException;
use Setono\Economic\Exception\MalformedResponseException;
use Setono\Economic\Exception\MethodNotAllowedException;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Exception\NotImplementedException;
use Setono\Economic\Exception\UnauthorizedException;
use Setono\Economic\Exception\UnexpectedStatusCodeException;
use Setono\Economic\Exception\ValidationException;
use Setono\Economic\Mapper\RawStamper;

final class Client implements ClientInterface
{
    private ?RequestInterface $lastRequest = null;

    private ?ResponseInterface $lastResponse = null;

    private ?InvoicesEndpoint $invoicesEndpoint = null;

    private ?OrdersEndpoint $ordersEndpoint = null;

    private ?ProductsEndpoint $productsEndpoint = null;

    private ?SelfEndpoint $selfEndpoint = null;

    private ?HttpClientInterface $httpClient = null;

    private ?RequestFactoryInterface $requestFactory = null;

    private ?MapperBuilder $mapperBuilder = null;

    public function __construct(private readonly string $appSecretToken, private readonly string $agreementGrantToken)
    {
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
            ->withHeader('User-Agent', $this->userAgent())
        ;

        $response = $this->getHttpClient()->sendRequest($request);

        $this->lastRequest = $request;
        $this->lastResponse = $response;

        self::assertStatusCode($response);

        return $response;
    }

    /**
     * GET the given URI and return the decoded JSON body. Accepts either:
     *  - a path relative to the e-conomic base URI (e.g. `"products"`), in which case `$query` is appended.
     *  - a fully-qualified URL pointing at the e-conomic API host (e.g. a `pagination.nextPage.url`), in which
     *    case `$query` MUST be empty (the URL already encodes its own query).
     *
     * Throws `\InvalidArgumentException` if an absolute URL is given that does not match the SDK's base host —
     * the SDK refuses to leak auth credentials to a different host.
     *
     * For non-JSON endpoints (PDFs, attachment files), use {@see self::request()} instead.
     *
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        $url = $this->resolveUrl($uri, $query);

        $request = $this->getRequestFactory()->createRequest('GET', $url);

        return $this->decodeJson($this->request($request));
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function resolveUrl(string $uri, array $query): string
    {
        if (preg_match('#^https?://#i', $uri) === 1) {
            $baseHost = parse_url($this->getBaseUri(), \PHP_URL_HOST);
            $uriHost = parse_url($uri, \PHP_URL_HOST);

            if ($baseHost !== $uriHost) {
                throw new InvalidUrlException(sprintf(
                    'Refusing to send a request to host "%s" — the e-conomic base host is "%s". '
                    . 'The SDK only sends auth credentials to its configured host.',
                    $uriHost ?? '(unparseable)',
                    $baseHost ?? '(unparseable)',
                ));
            }

            if ([] !== $query) {
                throw new InvalidUrlException(
                    'The $query parameter cannot be combined with an absolute URL — the URL already encodes its own query string.',
                );
            }

            return $uri;
        }

        $url = sprintf('%s/%s', $this->getBaseUri(), ltrim($uri, '/'));

        if ([] !== $query) {
            $url .= '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }

        return $url;
    }

    public function invoices(): InvoicesEndpoint
    {
        return $this->invoicesEndpoint ??= new InvoicesEndpoint($this, $this->getMapperBuilder());
    }

    public function orders(): OrdersEndpoint
    {
        return $this->ordersEndpoint ??= new OrdersEndpoint($this, $this->getMapperBuilder());
    }

    public function products(): ProductsEndpoint
    {
        return $this->productsEndpoint ??= new ProductsEndpoint($this, $this->getMapperBuilder());
    }

    public function self(): SelfEndpoint
    {
        return $this->selfEndpoint ??= new SelfEndpoint($this, $this->getMapperBuilder());
    }

    public function setMapperBuilder(MapperBuilder $mapperBuilder): void
    {
        $this->mapperBuilder = $mapperBuilder;
    }

    private function getMapperBuilder(): MapperBuilder
    {
        if (null === $this->mapperBuilder) {
            $this->mapperBuilder = new MapperBuilder()
                ->allowScalarValueCasting()
                ->allowNonSequentialList()
                ->allowUndefinedValues()
                ->allowSuperfluousKeys()
                // Stamp $raw on every Resource (envelope + items inside Collection<X>) as Valinor
                // maps it — see {@see RawStamper} for rationale on the template bound, the
                // class-vs-closure choice, and the purity suppression.
                ->registerConverter(new RawStamper())
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

    private function getBaseUri(): string
    {
        return 'https://restapi.e-conomic.com';
    }

    private function userAgent(): string
    {
        $version = InstalledVersions::getVersion('setono/economic-php-sdk') ?? 'dev';

        return sprintf('Setono-Economic-PHP/%s (+https://github.com/Setono/economic-php-sdk)', $version);
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

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if the body is not valid JSON or does not decode to an object
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedResponseException(
                $response,
                sprintf(
                    'Could not decode response body as JSON%s: %s. Body excerpt: %s',
                    $this->requestContext(),
                    $e->getMessage(),
                    self::excerpt($body),
                ),
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new MalformedResponseException(
                $response,
                sprintf(
                    'Expected decoded response body to be an array but got %s%s. Body excerpt: %s',
                    get_debug_type($decoded),
                    $this->requestContext(),
                    self::excerpt($body),
                ),
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded;

        return $result;
    }

    private function requestContext(): string
    {
        if (null === $this->lastRequest) {
            return '';
        }

        return sprintf(' [%s %s]', $this->lastRequest->getMethod(), (string) $this->lastRequest->getUri());
    }

    private static function excerpt(string $body, int $maxLen = 500): string
    {
        if (strlen($body) <= $maxLen) {
            return $body;
        }

        return substr($body, 0, $maxLen) . '... (truncated)';
    }

    private static function assertStatusCode(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        throw match ($statusCode) {
            400, 422 => new ValidationException($response),
            401 => new UnauthorizedException($response),
            403 => new ForbiddenException($response),
            404 => new NotFoundException($response),
            405 => new MethodNotAllowedException($response),
            500 => new InternalServerErrorException($response),
            501 => new NotImplementedException($response),
            default => new UnexpectedStatusCodeException($response),
        };
    }
}
