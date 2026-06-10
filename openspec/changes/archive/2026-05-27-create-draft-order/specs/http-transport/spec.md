## MODIFIED Requirements

### Requirement: Client collaborators are immutable after construction

The `Client` SHALL NOT expose any public method, property, or other mechanism for replacing its HTTP client, request factory, stream factory, mapper builder, or normalizer builder after construction. The collaborators stored at the end of `__construct` are the collaborators used for the entire lifetime of the instance.

#### Scenario: No setter methods exist on Client

- **WHEN** PHPStan or any reflection-based audit inspects the `Client` class
- **THEN** no method named `setHttpClient`, `setRequestFactory`, `setStreamFactory`, `setMapperBuilder`, or `setNormalizerBuilder` is present
- **AND** none of the collaborator-holding properties are `public`

#### Scenario: Re-wiring requires a fresh instance

- **WHEN** a consumer wants to switch from one PSR-18 client (or normalizer builder) to another mid-flow
- **THEN** they MUST construct a new `Client` instance with the desired collaborator
- **AND** the original `Client` instance continues to use its original wiring

## ADDED Requirements

### Requirement: Pluggable Valinor normalizer builder

The `Client` SHALL accept an optional `CuyZ\Valinor\NormalizerBuilder` as a named constructor argument (`normalizerBuilder:`). When omitted (or `null`), the `Client` MUST instantiate a default builder with two transformers registered: (1) an `Identifier` transformer that outputs `[$id->fieldName => $id->value]` for any `Setono\Economic\Request\Identifier` instance, and (2) a null-skipping transformer that fires on any object implementing the `Setono\Economic\Request\Payload` marker interface and strips `null` entries from the object's normalized array so optional request-DTO fields are absent (not `null`) from the produced JSON. The supplied (or default) builder is the one used by every `Client::post()` (and any future `put()` / `patch()` helper) for object → JSON serialization.

#### Scenario: Default normalizer builder is configured for the SDK's needs

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT')` without supplying a `NormalizerBuilder`
- **THEN** the `Client` builds a default `NormalizerBuilder` with the `Identifier` transformer and the `Payload` null-skipping transformer registered
- **AND** that builder is the one used by `Client::post()` to serialize request bodies

#### Scenario: Consumer-supplied normalizer builder is used end-to-end

- **WHEN** the consumer calls `new Client('TOKEN', 'AGREEMENT', normalizerBuilder: $customBuilder)` (for example with a cache configured)
- **THEN** `$customBuilder` is the builder used by every `Client::post()` call
- **AND** no default builder is constructed

#### Scenario: Consumer-supplied normalizer builder must register the Identifier transformer

- **WHEN** the consumer supplies a custom `NormalizerBuilder`
- **THEN** the SDK SHALL provide a static helper `Setono\Economic\Request\Identifier::registerTransformer(NormalizerBuilder): NormalizerBuilder` so consumers can append the Identifier transformer to their own builder without copying the closure
- **AND** the README MUST document this contract

### Requirement: POST accepts a typed request object and returns decoded JSON

The `Client` SHALL provide `post(string $uri, object $body): array<string, mixed>`. The method MUST: (1) resolve `$uri` against the SDK's base URI using the same rules as `Client::get()`, (2) normalize `$body` to a JSON string using the `NormalizerBuilder`'s `Format::json()` normalizer, (3) build a PSR-7 request via the constructor-injected request factory and stream factory, (4) dispatch via `Client::request()` (gaining auth headers, `User-Agent`, `Content-Type: application/json`, journaling, and status-code dispatch), and (5) return the decoded JSON response body as `array<string, mixed>`. The method MUST throw `MalformedResponseException` if the response body is not valid JSON or does not decode to an array.

The method MUST NOT accept a raw `array` payload. Consumers wanting to bypass the typed-DTO surface use `Client::request()` directly.

#### Scenario: Successful POST normalizes the body and decodes the response

- **WHEN** the consumer calls `$client->post('orders/drafts', $draftOrderRequest)` and the server returns 201 with a JSON object
- **THEN** the request body is the JSON representation of `$draftOrderRequest` produced by the configured `NormalizerBuilder`
- **AND** the request URI is `https://restapi.e-conomic.com/orders/drafts`
- **AND** the request method is `POST`
- **AND** the request headers include `Content-Type: application/json`, `X-AppSecretToken`, `X-AgreementGrantToken`, and `User-Agent`
- **AND** the return value is the decoded JSON body as `array<string, mixed>`

#### Scenario: Optional fields are omitted from the JSON body

- **WHEN** the consumer POSTs a typed request DTO with one or more optional properties left at their `null` default
- **THEN** the produced JSON body MUST NOT contain a key for any property whose value is `null`
- **AND** the keys that ARE present carry their non-null values

#### Scenario: Identifier instances serialize as `{<fieldName>: <value>}`

- **WHEN** a request DTO contains a field of type `Setono\Economic\Request\Identifier` constructed via one of its named factories (e.g. `Identifier::layout(17)`)
- **THEN** that field serializes as `{"layoutNumber": 17}` in the resulting JSON body
- **AND** every `Identifier::*` factory produces JSON keyed on the corresponding `<x>Number` field name

#### Scenario: Non-2xx response throws via the usual hierarchy

- **WHEN** `Client::post()` receives a non-2xx response
- **THEN** the typed exception matching the status code is thrown (see `error-handling` spec) — POST does not have a 404-to-null shortcut

#### Scenario: Malformed JSON response throws

- **WHEN** `Client::post()` receives a 2xx response whose body is not valid JSON or does not decode to an array
- **THEN** a `MalformedResponseException` is thrown with the request method, URI, and a body excerpt
