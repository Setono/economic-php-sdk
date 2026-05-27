<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use Composer\InstalledVersions;
use CuyZ\Valinor\MapperBuilder;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
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
    private const string BASE_URI = 'https://restapi.e-conomic.com';

    public private(set) ?RequestInterface $lastRequest = null;

    public private(set) ?ResponseInterface $lastResponse = null;

    private ?InvoicesEndpoint $invoicesEndpoint = null;

    private ?OrdersEndpoint $ordersEndpoint = null;

    private ?ProductsEndpoint $productsEndpoint = null;

    private ?SelfEndpoint $selfEndpoint = null;

    private readonly HttpClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    /**
     * Pre-wired for future request-body-producing endpoints (POST/PUT).
     * Stored but not consumed by any current internal code.
     */
    private readonly StreamFactoryInterface $streamFactory;

    private readonly MapperBuilder $mapperBuilder;

    public function __construct(
        private readonly string $appSecretToken,
        private readonly string $agreementGrantToken,
        ?HttpClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?MapperBuilder $mapperBuilder = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->mapperBuilder = $mapperBuilder ?? self::defaultMapperBuilder();
    }

    public function request(RequestInterface $request): ResponseInterface
    {
        $request = $request->withHeader('X-AppSecretToken', $this->appSecretToken)
            ->withHeader('X-AgreementGrantToken', $this->agreementGrantToken)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', $this->userAgent())
        ;

        $response = $this->httpClient->sendRequest($request);

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

        $request = $this->requestFactory->createRequest('GET', $url);
        $response = $this->request($request);

        return self::decodeJson($request, $response);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function resolveUrl(string $uri, array $query): string
    {
        if (preg_match('#^https?://#i', $uri) === 1) {
            $baseHost = parse_url(self::BASE_URI, \PHP_URL_HOST);
            $uriHost = parse_url($uri, \PHP_URL_HOST);

            if ($baseHost !== $uriHost) {
                throw new InvalidUrlException(sprintf(
                    'Refusing to send a request to host "%s" — the e-conomic base host is "%s". '
                    . 'The SDK only sends auth credentials to its configured host.',
                    $uriHost ?? '(unparseable)',
                    $baseHost,
                ));
            }

            if ([] !== $query) {
                throw new InvalidUrlException(
                    'The $query parameter cannot be combined with an absolute URL — the URL already encodes its own query string.',
                );
            }

            return $uri;
        }

        if ([] !== $query && str_contains($uri, '?')) {
            throw new InvalidUrlException(
                'The $query parameter cannot be combined with a URI that already contains a query string — '
                . 'pick one source of the query string, not both.',
            );
        }

        $url = sprintf('%s/%s', self::BASE_URI, ltrim($uri, '/'));

        if ([] !== $query) {
            $url .= '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }

        return $url;
    }

    public function invoices(): InvoicesEndpoint
    {
        return $this->invoicesEndpoint ??= new InvoicesEndpoint($this, $this->mapperBuilder);
    }

    public function orders(): OrdersEndpoint
    {
        return $this->ordersEndpoint ??= new OrdersEndpoint($this, $this->mapperBuilder);
    }

    public function products(): ProductsEndpoint
    {
        return $this->productsEndpoint ??= new ProductsEndpoint($this, $this->mapperBuilder);
    }

    public function self(): SelfEndpoint
    {
        return $this->selfEndpoint ??= new SelfEndpoint($this, $this->mapperBuilder);
    }

    /**
     * Exposes the resolved PSR-17 stream factory so future request-body-producing endpoints
     * (and consumers building custom PSR-7 requests for {@see self::request()}) can reuse it
     * instead of discovering or constructing their own.
     */
    public function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    /**
     * The default Valinor {@see MapperBuilder} the SDK uses when no consumer-supplied builder
     * is injected. See {@see RawStamper} for the rationale on the registered converter.
     */
    private static function defaultMapperBuilder(): MapperBuilder
    {
        return new MapperBuilder()
            ->allowScalarValueCasting()
            ->allowNonSequentialList()
            ->allowUndefinedValues()
            ->allowSuperfluousKeys()
            ->registerConverter(new RawStamper())
        ;
    }

    private function userAgent(): string
    {
        $version = InstalledVersions::getVersion('setono/economic-php-sdk') ?? 'dev';

        return sprintf('Setono-Economic-PHP/%s (+https://github.com/Setono/economic-php-sdk)', $version);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MalformedResponseException if the body is not valid JSON or does not decode to an object
     */
    private static function decodeJson(RequestInterface $request, ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $context = sprintf(' [%s %s]', $request->getMethod(), (string) $request->getUri());

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedResponseException(
                $response,
                sprintf(
                    'Could not decode response body as JSON%s: %s. Body excerpt: %s',
                    $context,
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
                    $context,
                    self::excerpt($body),
                ),
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded;

        return $result;
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
