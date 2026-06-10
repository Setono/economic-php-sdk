<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use Composer\InstalledVersions;
use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Normalizer\Format;
use CuyZ\Valinor\NormalizerBuilder;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Setono\Economic\Client\Endpoint\CustomersEndpoint;
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
use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Payload;

final class Client implements ClientInterface
{
    private const string BASE_URI = 'https://restapi.e-conomic.com';

    public private(set) ?RequestInterface $lastRequest = null;

    public private(set) ?ResponseInterface $lastResponse = null;

    private ?CustomersEndpoint $customersEndpoint = null;

    private ?InvoicesEndpoint $invoicesEndpoint = null;

    private ?OrdersEndpoint $ordersEndpoint = null;

    private ?ProductsEndpoint $productsEndpoint = null;

    private ?SelfEndpoint $selfEndpoint = null;

    private readonly HttpClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly MapperBuilder $mapperBuilder;

    private readonly NormalizerBuilder $normalizerBuilder;

    public function __construct(
        private readonly string $appSecretToken,
        private readonly string $agreementGrantToken,
        ?HttpClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?MapperBuilder $mapperBuilder = null,
        ?NormalizerBuilder $normalizerBuilder = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->mapperBuilder = $mapperBuilder ?? self::defaultMapperBuilder();
        $this->normalizerBuilder = $normalizerBuilder ?? self::defaultNormalizerBuilder();

        // When a consumer supplied their own NormalizerBuilder, eagerly probe it to make sure
        // the SDK's Identifier + Payload transformers are wired. Catches the silent-corruption
        // case where a consumer caches a bare NormalizerBuilder and forgets the helper call.
        // Default builder always passes; probe is fast (single Identifier normalization).
        if (null !== $normalizerBuilder) {
            self::assertNormalizerBuilderConfigured($this->normalizerBuilder);
        }
    }

    public function request(RequestInterface $request): ResponseInterface
    {
        $request = $request->withHeader('X-AppSecretToken', $this->appSecretToken)
            ->withHeader('X-AgreementGrantToken', $this->agreementGrantToken)
            ->withHeader('User-Agent', $this->userAgent())
        ;

        // Only stamp Content-Type when absent — preserve a consumer-supplied value so the
        // documented escape hatch (e.g. binary uploads via `Client::request()`) actually
        // escapes. See `openspec/specs/http-transport/spec.md`.
        if (!$request->hasHeader('Content-Type')) {
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        $response = $this->httpClient->sendRequest($request);

        $this->lastRequest = $request;
        $this->lastResponse = $response;

        self::assertStatusCode($request, $response);

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
        $request = $this->requestFactory->createRequest('GET', $this->resolveUrl($uri, $query));

        return self::decodeJson($request, $this->request($request));
    }

    /**
     * POST a typed request DTO to `$uri` and return the decoded JSON body. The body is
     * normalized to JSON via the constructor-injected (or default) {@see NormalizerBuilder};
     * optional DTO properties that are `null` are stripped via the `Payload` null-skipping
     * transformer registered on the default builder.
     *
     * Strictly typed: `$body` is `object`, not `object|array`. Consumers wanting to POST a
     * raw-array payload use {@see self::request()} directly.
     *
     * @return array<string, mixed>
     */
    public function post(string $uri, Payload $body): array
    {
        $request = $this->requestFactory
            ->createRequest('POST', $this->resolveUrl($uri))
            ->withBody(
                $this->streamFactory->createStream(
                    $this->normalizerBuilder->normalizer(Format::json())->normalize($body),
                ),
            )
        ;

        return self::decodeJson($request, $this->request($request));
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function resolveUrl(string $uri, array $query = []): string
    {
        if (preg_match('#^https?://#i', $uri) === 1) {
            // RFC 3986 hosts are case-insensitive — normalize both sides so a server-issued
            // `pagination.nextPage.url` like `https://RESTAPI.E-CONOMIC.COM/...` is accepted
            // without leaking auth credentials to a foreign host by accident.
            $baseHost = strtolower(self::parseStringPart(self::BASE_URI, \PHP_URL_HOST));
            $uriHost = strtolower(self::parseStringPart($uri, \PHP_URL_HOST));

            if ($baseHost !== $uriHost) {
                throw new InvalidUrlException(sprintf(
                    'Refusing to send a request to host "%s" — the e-conomic base host is "%s". '
                    . 'The SDK only sends auth credentials to its configured host.',
                    '' === $uriHost ? '(unparseable)' : $uriHost,
                    $baseHost,
                ));
            }

            // Port hardening: reject any explicit port that doesn't match the scheme's default.
            // e-conomic is HTTPS-only and uses the default 443. An explicit non-default port
            // (e.g. https://restapi.e-conomic.com:9999/...) would otherwise pass the host check
            // and ship auth headers to an arbitrary port — a credential-exfil path under
            // DNS/network compromise. `parse_url` returns `null` when no explicit port is in
            // the URL; we accept that and reject any non-null mismatch.
            $port = parse_url($uri, \PHP_URL_PORT);
            $scheme = strtolower(self::parseStringPart($uri, \PHP_URL_SCHEME));
            $defaultPort = 'https' === $scheme ? 443 : ('http' === $scheme ? 80 : null);
            if (null !== $port && $port !== $defaultPort) {
                throw new InvalidUrlException(sprintf(
                    'Refusing to send a request to non-default port %d on the e-conomic host. '
                    . 'The SDK only sends auth credentials to the default port for the URL scheme.',
                    $port,
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

    public function customers(): CustomersEndpoint
    {
        return $this->customersEndpoint ??= new CustomersEndpoint($this, $this->mapperBuilder);
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
     * Exposes the resolved Valinor {@see NormalizerBuilder} so future write endpoints (and
     * consumers building custom serialization pipelines) can reuse the builder the SDK is
     * already using to normalize POST bodies — including its `Identifier` transformer and
     * `Payload` null-skipper. Not part of {@see ClientInterface} (mirrors `getStreamFactory()`).
     */
    public function getNormalizerBuilder(): NormalizerBuilder
    {
        return $this->normalizerBuilder;
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

    /**
     * Append both SDK normalizer transformers — `Identifier` and `Payload` null-skipping — to
     * a consumer-supplied {@see NormalizerBuilder}. This is the single entry point consumers
     * SHOULD use when wiring a custom builder (e.g. with a `FileSystemCache` for production):
     *
     * ```
     * $custom = (new NormalizerBuilder())->withCache($cache);
     * $custom = Client::registerNormalizerTransformers($custom);
     * $client = new Client('TOKEN', 'AGREEMENT', normalizerBuilder: $custom);
     * ```
     *
     * Without this helper, a custom builder will serialize `Identifier` objects with Valinor's
     * default object-normalization shape (`{"fieldName":"...","value":...}`) — which e-conomic
     * rejects — and will not strip `null` entries from `Payload` request DTOs.
     *
     * Note: {@see NormalizerBuilder::registerTransformer()} matches transformers in
     * last-registered-first order. Registering a competing transformer for `Identifier` or
     * `Payload` AFTER this call will shadow the SDK's rules without warning, producing JSON
     * that e-conomic will reject.
     *
     * The method is `@pure` — `NormalizerBuilder` is immutable; the returned builder MUST be
     * the one passed to the `Client` constructor.
     */
    public static function registerNormalizerTransformers(NormalizerBuilder $builder): NormalizerBuilder
    {
        $builder = Identifier::registerTransformer($builder);

        return $builder->registerTransformer(
            /**
             * @return array<int|string, mixed>
             */
            static function (Payload $payload, callable $next): array {
                /** @var array<int|string, mixed> $normalized */
                $normalized = $next();

                // Strip both `null` AND `[]`. The empty-array strip handles the recursive case
                // where a nested `Payload` (e.g. `Accrual` with all-null fields) normalizes to
                // `[]`; without this filter the parent ships `"field":[]` instead of omitting
                // the key. Consumers who legitimately need to send an explicit empty array
                // must drop down to `Client::request()` with a hand-built payload.
                return array_filter(
                    $normalized,
                    static fn (mixed $value): bool => $value !== null && $value !== [],
                );
            },
        );
    }

    /**
     * The default Valinor {@see NormalizerBuilder} used to serialize request DTOs into JSON.
     * Both SDK transformers are pre-registered via {@see self::registerNormalizerTransformers()}.
     */
    private static function defaultNormalizerBuilder(): NormalizerBuilder
    {
        return self::registerNormalizerTransformers(new NormalizerBuilder());
    }

    /**
     * Probe the supplied (or default) `NormalizerBuilder` to confirm the SDK's transformers
     * are wired. Catches the silent-data-corruption case where a consumer supplies a custom
     * `NormalizerBuilder` for caching but forgets to call
     * {@see self::registerNormalizerTransformers()} before passing it in.
     *
     * Probes `Identifier::layout(1)`; if the output is not `['layoutNumber' => 1]` we know
     * the `Identifier` transformer is missing (and almost certainly the `Payload` null-skipper
     * is missing too, since the SDK helper registers both at once).
     *
     * Single-shot cost at construction time; zero per-request overhead.
     */
    private static function assertNormalizerBuilderConfigured(NormalizerBuilder $builder): void
    {
        $identifierOutput = $builder->normalizer(Format::array())->normalize(Identifier::layout(1));

        if ($identifierOutput !== ['layoutNumber' => 1]) {
            throw new \LogicException(
                'The supplied NormalizerBuilder does not have the SDK\'s transformers registered. '
                . 'Identifier::layout(1) normalized to ' . json_encode($identifierOutput) . ' instead '
                . 'of {"layoutNumber":1}. Call Client::registerNormalizerTransformers($yourBuilder) '
                . 'before passing the builder to the Client constructor.',
            );
        }
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

        // Strip query + fragment so consumer-supplied secrets (e.g. a token in $query) don't
        // land in exception messages or logs.
        $sanitizedUri = $request->getUri()->withQuery('')->withFragment('');
        $context = sprintf(' [%s %s]', $request->getMethod(), (string) $sanitizedUri);

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
                body: $body,
                request: $request,
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
                body: $body,
                request: $request,
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded;

        return $result;
    }

    /**
     * `parse_url` for a single string-typed component (host/scheme/path). Returns `''` for
     * the `null` / `false` cases so callers don't need to handle them separately.
     */
    private static function parseStringPart(string $url, int $component): string
    {
        $value = parse_url($url, $component);

        return is_string($value) ? $value : '';
    }

    private static function excerpt(string $body, int $maxLen = 500): string
    {
        if (strlen($body) <= $maxLen) {
            return $body;
        }

        return substr($body, 0, $maxLen) . '... (truncated)';
    }

    private static function assertStatusCode(RequestInterface $request, ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        // Pre-read the body ONCE here so the lazy-parse getters on the exception
        // (getErrorCode, getLogId, getValidationErrors, …) work even when the
        // underlying PSR-7 stream is not seekable. The pre-read string is threaded
        // into the exception via the `body:` named argument.
        $body = (string) $response->getBody();

        throw match ($statusCode) {
            400, 422 => new ValidationException($response, body: $body, request: $request),
            401 => new UnauthorizedException($response, body: $body, request: $request),
            403 => new ForbiddenException($response, body: $body, request: $request),
            404 => new NotFoundException($response, body: $body, request: $request),
            405 => new MethodNotAllowedException($response, body: $body, request: $request),
            500 => new InternalServerErrorException($response, body: $body, request: $request),
            501 => new NotImplementedException($response, body: $body, request: $request),
            default => new UnexpectedStatusCodeException($response, body: $body, request: $request),
        };
    }
}
