## MODIFIED Requirements

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

## ADDED Requirements

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
