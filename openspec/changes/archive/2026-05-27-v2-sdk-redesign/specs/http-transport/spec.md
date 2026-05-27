## ADDED Requirements

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

The `Client` SHALL execute requests through a consumer-supplied PSR-18 client (auto-discovered if not set), and it MUST expose the last sent request and last received response via `getLastRequest()` and `getLastResponse()`.

#### Scenario: Last request and response are journaled

- **WHEN** the consumer makes any request
- **THEN** `Client::getLastRequest()` returns the exact PSR-7 request that was sent (with auth and `User-Agent` headers applied)
- **AND** `Client::getLastResponse()` returns the exact PSR-7 response that came back

#### Scenario: Pristine client returns null

- **WHEN** no request has been made yet on a fresh `Client` instance
- **THEN** `Client::getLastRequest()` returns `null`
- **AND** `Client::getLastResponse()` returns `null`

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

The `Client` SHALL accept a `ClientInterface` (PSR-18) via `setHttpClient(?ClientInterface)` and a `RequestFactoryInterface` (PSR-17) via `setRequestFactory(?RequestFactoryInterface)`. When neither is set, auto-discovery (`php-http/discovery`) MUST be used.

#### Scenario: Consumer-supplied PSR-18 client is used

- **WHEN** the consumer calls `Client::setHttpClient($customClient)` before making any request
- **THEN** `$customClient->sendRequest()` is the call used to dispatch the HTTP request

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
