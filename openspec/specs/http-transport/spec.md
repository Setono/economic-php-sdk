# http-transport Specification

## Purpose

Defines the `Client`'s HTTP transport behavior: how it authenticates (the `X-AppSecretToken` / `X-AgreementGrantToken` header pair), how it identifies itself (`User-Agent`), how it journals the last request and response, the JSON-aware `get()` helper (relative path or absolute URL pointing at the SDK's host), the generic `request()` escape hatch for non-JSON or non-wrapped endpoints, pluggable PSR-18 / PSR-17 wiring with `php-http/discovery` fallback, retention of `ClientInterface` as a testability seam, and the deliberate absence of logger plumbing.

## Requirements

### Requirement: Client authentication

The `Client` SHALL be constructed with an app secret token and an agreement grant token, and it MUST stamp both as headers (`X-AppSecretToken` and `X-AgreementGrantToken`) on every outgoing request.

#### Scenario: Tokens stamped on requests

- **WHEN** the consumer calls any method on `Client` that triggers an HTTP request
- **THEN** the outgoing PSR-7 request carries `X-AppSecretToken` set to the value passed to the constructor
- **AND** the outgoing PSR-7 request carries `X-AgreementGrantToken` set to the value passed to the constructor
- **AND** the outgoing PSR-7 request carries `Content-Type: application/json`

### Requirement: User-Agent identification

The `Client` SHALL stamp a `User-Agent` header on every outgoing request that identifies this SDK and the installed package version.

#### Scenario: User-Agent format with known version

- **WHEN** the consumer makes any request
- **AND** `Composer\InstalledVersions::getVersion('setono/economic-php-sdk')` returns a non-null version string
- **THEN** the outgoing request carries a `User-Agent` header of the form `Setono-Economic-PHP/<version> (+https://github.com/Setono/economic-php-sdk)`

#### Scenario: User-Agent fallback when version unknown

- **WHEN** the consumer makes any request
- **AND** `Composer\InstalledVersions::getVersion('setono/economic-php-sdk')` returns `null`
- **THEN** the outgoing request carries a `User-Agent` header of the form `Setono-Economic-PHP/dev (+https://github.com/Setono/economic-php-sdk)`

### Requirement: Request lifecycle and journaling

The `Client` SHALL execute requests through its constructor-injected PSR-18 client (auto-discovered when no `httpClient` argument is passed), and it MUST expose the last sent request and last received response as the `public ?RequestInterface $lastRequest` and `public ?ResponseInterface $lastResponse` virtual properties declared by `ClientInterface`. Both properties are publicly readable but the setter is `private` — only the `Client` itself updates them. The legacy `getLastRequest()` / `getLastResponse()` accessor methods are removed; consumers read the properties directly.

#### Scenario: Last request and response are journaled

- **WHEN** the consumer makes any request
- **THEN** `$client->lastRequest` is the exact PSR-7 request that was sent (with auth and `User-Agent` headers applied)
- **AND** `$client->lastResponse` is the exact PSR-7 response that came back

#### Scenario: Pristine client returns null

- **WHEN** no request has been made yet on a fresh `Client` instance
- **THEN** `$client->lastRequest` is `null`
- **AND** `$client->lastResponse` is `null`

### Requirement: Status code dispatch

The `Client` SHALL inspect the response status code and dispatch a typed exception for any non-2xx response. The dispatch MUST be centralized inside the client (not delegated to per-exception static factories).

#### Scenario: 2xx passes through

- **WHEN** the server returns a status code in `[200, 299]`
- **THEN** the response is returned to the caller without an exception

#### Scenario: 4xx and 5xx codes throw typed exceptions

- **WHEN** the server returns a status code outside `[200, 299]`
- **THEN** the client throws a `ResponseAwareException` of the most specific type that matches the code (see `error-handling` spec)

### Requirement: GET accepts a path or an absolute URL and returns decoded JSON

The `Client` SHALL provide `get(string $uri, array $query = []): array<string, mixed>` that accepts either a path relative to the e-conomic base URI or a fully-qualified URL pointing at the e-conomic API host, and returns the decoded JSON body. The method MUST throw `\RuntimeException` (wrapping any underlying `\JsonException`) if the body is not valid JSON or does not decode to an array.

For non-JSON endpoints (PDF downloads, attachment files), consumers MUST use `Client::request()` instead.

#### Scenario: Relative path with no query

- **WHEN** the consumer calls `$client->get('products')`
- **THEN** the request URI is `https://restapi.e-conomic.com/products` with no query string
- **AND** the return value is the decoded JSON body as an `array<string, mixed>`

#### Scenario: Relative path with query

- **WHEN** the consumer calls `$client->get('products', ['skippages' => 0, 'pagesize' => 20])`
- **THEN** the request URI is `https://restapi.e-conomic.com/products?skippages=0&pagesize=20` (RFC 3986 encoded)
- **AND** the return value is the decoded JSON body

#### Scenario: Empty query produces no question mark

- **WHEN** the consumer calls `$client->get('products', [])`
- **THEN** the request URI has no `?` and no query string

#### Scenario: Absolute URL is used verbatim

- **WHEN** the consumer (or the pagination walker) calls `$client->get('https://restapi.e-conomic.com/products?skippages=2&pagesize=20')`
- **THEN** the request is sent to that exact URL with no base URI prefix added
- **AND** auth headers and `User-Agent` are still applied
- **AND** the return value is the decoded JSON body

#### Scenario: Malformed JSON body throws

- **WHEN** `Client::get()` receives a 2xx response whose body is not valid JSON
- **THEN** a `\RuntimeException` is thrown
- **AND** the message contains the request method, URI, and an excerpt of the response body
- **AND** the exception is a `MalformedResponseException` (also an `EconomicException`, also a `ResponseAwareException` so the consumer can call `$e->getResponse()` and the lazy-parse getters)

### Requirement: GET refuses absolute URLs pointing to a foreign host

When `Client::get()` is given an absolute URL (starting with `http://` or `https://`), the URL's host MUST match the host of the SDK's base URI. The SDK MUST throw `InvalidUrlException` BEFORE dispatching the request when the host differs, so auth credentials are never leaked to a non-e-conomic host.

#### Scenario: Foreign-host absolute URL is rejected

- **WHEN** the consumer calls `$client->get('https://attacker.example.com/products')`
- **THEN** an `InvalidUrlException` is thrown
- **AND** no HTTP request is dispatched

### Requirement: GET rejects combining absolute URL with query parameters

When `Client::get()` is given an absolute URL together with a non-empty `$query` array, the SDK MUST throw `InvalidUrlException`. The absolute URL already encodes its own query string; combining two query sources is ambiguous.

#### Scenario: Absolute URL plus query throws

- **WHEN** the consumer calls `$client->get('https://restapi.e-conomic.com/products?a=1', ['b' => 2])`
- **THEN** an `InvalidUrlException` is thrown

### Requirement: Generic request escape hatch

The `Client` SHALL provide `request(RequestInterface $request): ResponseInterface` so consumers can build arbitrary PSR-7 requests and still benefit from auth, `User-Agent`, journaling, and status-code dispatch.

#### Scenario: Custom request gets auth and User-Agent applied

- **WHEN** the consumer builds a PSR-7 request and passes it to `Client::request()`
- **THEN** the auth headers, `Content-Type: application/json`, and `User-Agent` are applied
- **AND** the response is journaled and dispatched through status-code mapping

### Requirement: Pluggable PSR-18 client and PSR-17 request factory

The `Client` SHALL accept its HTTP collaborators — a `Psr\Http\Client\ClientInterface` (PSR-18), a `Psr\Http\Message\RequestFactoryInterface` (PSR-17), and a `Psr\Http\Message\StreamFactoryInterface` (PSR-17) — as optional, named constructor arguments. When any of the three is omitted (or passed as `null`), the `Client` MUST resolve the missing collaborator via `php-http/discovery` (`Psr18ClientDiscovery::find()` for the HTTP client, `Psr17FactoryDiscovery::findRequestFactory()` and `Psr17FactoryDiscovery::findStreamFactory()` for the factories). Discovery MUST run eagerly inside `Client::__construct`, not lazily on first use.

The `Client` MUST NOT expose post-construction setters for any of these collaborators. Once constructed, the wiring is fixed.

#### Scenario: Zero-config construction triggers discovery

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT')` without injecting any HTTP collaborators
- **THEN** the `Client` resolves the PSR-18 client via `Psr18ClientDiscovery::find()`
- **AND** resolves the PSR-17 request factory via `Psr17FactoryDiscovery::findRequestFactory()`
- **AND** resolves the PSR-17 stream factory via `Psr17FactoryDiscovery::findStreamFactory()`
- **AND** all three resolutions happen inside the constructor (not deferred)

#### Scenario: Consumer-supplied PSR-18 client is used

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT', httpClient: $customClient)`
- **THEN** `$customClient->sendRequest()` is the call used to dispatch every subsequent HTTP request
- **AND** no discovery is performed for the HTTP client

#### Scenario: Consumer-supplied request factory is used

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT', requestFactory: $customRequestFactory)`
- **THEN** `$customRequestFactory->createRequest(...)` is used to build PSR-7 requests inside `Client::get()`
- **AND** no discovery is performed for the request factory

#### Scenario: Consumer-supplied stream factory is stored even if unused today

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT', streamFactory: $customStreamFactory)`
- **THEN** the `Client` retains `$customStreamFactory` for use by future request-body-producing methods
- **AND** no discovery is performed for the stream factory

#### Scenario: Setter methods are not available

- **WHEN** code attempts to call `$client->setHttpClient(...)`, `$client->setRequestFactory(...)`, or `$client->setMapperBuilder(...)`
- **THEN** the call MUST be a compile/static-analysis error because no such method exists on `Client`

### Requirement: `ClientInterface` is preserved as a testability seam

The package SHALL retain `ClientInterface` covering the public Client surface so that downstream consumers (and SDK internals like `Endpoint`) can type-hint and mock against an interface rather than the concrete class.

#### Scenario: Endpoints depend on `ClientInterface`

- **WHEN** an `Endpoint` subclass is constructed
- **THEN** its `$client` dependency is typed as `ClientInterface`, not as the concrete `Client`

### Requirement: No logger plumbing

The `Client` and the `Endpoint` base class MUST NOT implement `LoggerAwareInterface`. The package MUST NOT require `psr/log`. Any error context that would have been logged MUST be embedded in the thrown exception's message instead.

#### Scenario: psr/log is not required

- **WHEN** the package's `composer.json` is read
- **THEN** `psr/log` does not appear in the `require` section

### Requirement: Pluggable Valinor mapper builder

The `Client` SHALL accept an optional `CuyZ\Valinor\MapperBuilder` as a named constructor argument. When omitted (or `null`), the `Client` MUST instantiate a default builder configured with `allowScalarValueCasting()`, `allowNonSequentialList()`, `allowUndefinedValues()`, `allowSuperfluousKeys()`, and the project's `RawStamper` converter — equivalent to the lazy default used in v2 today.

The supplied (or default) `MapperBuilder` MUST be the one passed to every endpoint constructed by the `Client`'s accessor methods (`products()`, `orders()`, `invoices()`, `self()`).

#### Scenario: Default mapper builder is configured for the SDK's needs

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT')` without supplying a `MapperBuilder`
- **THEN** the `Client` builds a default `MapperBuilder` with `allowScalarValueCasting()`, `allowNonSequentialList()`, `allowUndefinedValues()`, `allowSuperfluousKeys()`, and the `RawStamper` converter registered
- **AND** that builder is passed to each endpoint when its accessor is called

#### Scenario: Consumer-supplied mapper builder is used end-to-end

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT', mapperBuilder: $customBuilder)` (for example with a `FileSystemCache` configured)
- **THEN** `$customBuilder` is the builder passed to every endpoint
- **AND** no default builder is constructed

### Requirement: GET rejects combining a relative URI containing a query string with `$query`

When `Client::get()` is given a relative path that already contains a `?` (its own embedded query string) together with a non-empty `$query` array, the SDK MUST throw `InvalidUrlException` BEFORE dispatching the request. This mirrors the existing absolute-URL rejection rule: the caller picks one source of the query string, not both. A relative URI with an embedded query and an empty `$query` is still valid.

#### Scenario: Relative URI with embedded query plus non-empty `$query` throws

- **WHEN** the consumer calls `$client->get('products?embed=lines', ['pagesize' => 20])`
- **THEN** an `InvalidUrlException` is thrown
- **AND** no HTTP request is dispatched

#### Scenario: Relative URI with embedded query and empty `$query` is allowed

- **WHEN** the consumer calls `$client->get('products?embed=lines')` with no `$query`
- **THEN** the request URI is `https://restapi.e-conomic.com/products?embed=lines`
- **AND** the request is dispatched normally

### Requirement: Client collaborators are immutable after construction

The `Client` SHALL NOT expose any public method, property, or other mechanism for replacing its HTTP client, request factory, stream factory, or mapper builder after construction. The collaborators stored at the end of `__construct` are the collaborators used for the entire lifetime of the instance.

#### Scenario: No setter methods exist on Client

- **WHEN** PHPStan or any reflection-based audit inspects the `Client` class
- **THEN** no method named `setHttpClient`, `setRequestFactory`, `setStreamFactory`, or `setMapperBuilder` is present
- **AND** none of the collaborator-holding properties are `public`

#### Scenario: Re-wiring requires a fresh instance

- **WHEN** a consumer wants to switch from one PSR-18 client to another (for example to add logging middleware mid-flow)
- **THEN** they MUST construct a new `Client` instance with the desired client
- **AND** the original `Client` instance continues to use its original wiring
