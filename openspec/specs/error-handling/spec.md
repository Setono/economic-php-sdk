# error-handling Specification

## Purpose

Defines the SDK's exception hierarchy: the `EconomicException` marker interface (so consumers can net every SDK throw), the abstract `ResponseAwareException` that carries the PSR-7 response and lazily parses e-conomic's error-body fields (`errorCode`, `developerHint`, `logId`, `logTime`, validation errors), the status-code-keyed concrete classes (`UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `MethodNotAllowedException`, `ValidationException`, `InternalServerErrorException`, `NotImplementedException`, plus the catch-all `UnexpectedStatusCodeException`), the `MalformedResponseException` for 2xx bodies that fail to decode, the pre-flight `InvalidUrlException`, and the rule that dispatch lives in the client (not in per-exception static factories).

## Requirements

### Requirement: `EconomicException` marker interface

The package SHALL provide an `EconomicException` interface that every exception thrown by this SDK MUST implement. Consumers MUST be able to write `catch (EconomicException $e)` to net all SDK-thrown exceptions without catching `\Throwable`.

#### Scenario: All SDK exceptions implement the marker

- **WHEN** any concrete SDK exception class is inspected via reflection
- **THEN** it implements `Setono\Economic\Exception\EconomicException`

### Requirement: `ResponseAwareException` carries response and lazily parses e-conomic error fields

The package SHALL provide an abstract `ResponseAwareException extends \RuntimeException implements EconomicException` that holds the PSR-7 response. It MUST expose:

- `getResponse(): ResponseInterface`
- `getErrorCode(): ?int` — reads e-conomic's `errorCode` from the JSON body
- `getDeveloperHint(): ?string` — reads `developerHint`
- `getLogId(): ?string` — reads `logId`
- `getLogTime(): ?\DateTimeImmutable` — reads `logTime` (ISO-8601 timestamp)
- `getValidationErrors(): array<string, mixed>` — reads the `errors` field verbatim as a raw nested document mirroring e-conomic's wire format (NOT a flattened list). Each leaf may be `{errors: [{errorCode, message, value, developerHint}]}`; array fields carry per-index error blocks with an `arrayIndex` marker. Returns `[]` if the field is absent.

Parsing MUST be lazy (deferred until the first getter call) and memoized. Getters MUST NOT throw if the body is empty, missing the field, or not valid JSON — they MUST return `null` (or `[]` for `getValidationErrors`).

#### Scenario: Lazy parsing on first call

- **WHEN** a `ResponseAwareException` is constructed but no getter is called
- **THEN** the body has not been JSON-decoded

- **WHEN** `getErrorCode()` is called for the first time
- **THEN** the body is JSON-decoded once and the result is stored for subsequent calls

#### Scenario: Malformed body does not throw

- **WHEN** the response body is empty or not valid JSON
- **AND** `getErrorCode()`, `getDeveloperHint()`, `getLogId()`, or `getValidationErrors()` is called
- **THEN** the getter returns `null` (or `[]` for validation errors) — no exception escapes

#### Scenario: logId is surfaced

- **WHEN** the response body contains `{"logId": "abc-123", ...}`
- **THEN** `getLogId()` returns `"abc-123"`

#### Scenario: logTime is parsed as DateTimeImmutable

- **WHEN** the response body contains `{"logTime": "2015-03-12T16:44:56", ...}`
- **THEN** `getLogTime()` returns a `\DateTimeImmutable` representing that instant

#### Scenario: Validation errors returned as raw nested document

- **WHEN** the response body contains an `errors` object with nested field paths (e.g. `errors.lines[0].unitNetPrice.errors`)
- **THEN** `getValidationErrors()` returns the entire `errors` value as a nested array, preserving the structure verbatim
- **AND** consumers are expected to walk the structure themselves

### Requirement: Status-code-keyed exception hierarchy

The package SHALL define a hierarchy of concrete exception classes mapping to HTTP status codes:

```
EconomicException                   (interface)
├── ResponseAwareException          (abstract; HTTP response carrier)
│   ├── ClientErrorException        (abstract; 4xx)
│   │   ├── UnauthorizedException    (401)
│   │   ├── ForbiddenException       (403)
│   │   ├── NotFoundException        (404)
│   │   ├── MethodNotAllowedException(405)
│   │   └── ValidationException      (400 + 422)
│   ├── ServerErrorException        (abstract; 5xx)
│   │   ├── InternalServerErrorException (500)
│   │   └── NotImplementedException     (501)
│   ├── UnexpectedStatusCodeException   (catch-all for unmatched non-2xx; e.g. 415, 502, 504)
│   └── MalformedResponseException      (2xx but body is not the JSON object we expected)
└── InvalidUrlException              (also extends \InvalidArgumentException; pre-flight)
```

The catch-all `UnexpectedStatusCodeException extends ResponseAwareException` is for non-2xx codes that match none of the named subclasses (415, 418, 502, 503, 504, etc.). `415 Unsupported Media Type` deliberately does NOT get a named subclass — it indicates an SDK bug (we sent the wrong `Content-Type`), not a consumer-recoverable condition.

`MalformedResponseException` covers the case where a 2xx response body could not be decoded as the JSON object the SDK expects. It extends `ResponseAwareException` so consumers can still call `getResponse()` and the lazy-parse getters (which degrade to `null` / `[]` on malformed JSON).

`InvalidUrlException` is the only SDK exception that is NOT `ResponseAwareException` — it's pre-flight, before any HTTP dispatch. It extends PHP's `\InvalidArgumentException` so the stdlib catch shape still works, AND implements `EconomicException` so the marker net still catches it.

#### Scenario: 401 throws UnauthorizedException

- **WHEN** the API returns 401
- **THEN** the client throws `UnauthorizedException`
- **AND** the thrown exception is also a `ClientErrorException` and a `ResponseAwareException` and an `EconomicException`

#### Scenario: 422 throws ValidationException

- **WHEN** the API returns 422 with structured validation errors in the body
- **THEN** the client throws `ValidationException`
- **AND** `getValidationErrors()` returns the list

#### Scenario: 400 also throws ValidationException

- **WHEN** the API returns 400
- **THEN** the client throws `ValidationException`

#### Scenario: 405 throws MethodNotAllowedException

- **WHEN** the API returns 405
- **THEN** the client throws `MethodNotAllowedException`

#### Scenario: 501 throws NotImplementedException

- **WHEN** the API returns 501
- **THEN** the client throws `NotImplementedException`

#### Scenario: 502 falls back to UnexpectedStatusCodeException

- **WHEN** the API returns 502
- **THEN** the client throws `UnexpectedStatusCodeException` (which is still a `ResponseAwareException`)

#### Scenario: 415 falls back to UnexpectedStatusCodeException

- **WHEN** the API returns 415
- **THEN** the client throws `UnexpectedStatusCodeException` (no named subclass for 415)

#### Scenario: Catch-all marker works

- **WHEN** the consumer writes `try { ... } catch (EconomicException $e) { ... }`
- **AND** the SDK throws any of the concrete exceptions above
- **THEN** the catch block matches

### Requirement: No 429 dispatch (e-conomic does not document 429)

e-conomic's HTTP Status Codes documentation does not include 429 Too Many Requests. The SDK therefore SHALL NOT include a `RateLimitException` class, and 429 (should the API ever return one) MUST fall through to `UnexpectedStatusCodeException`. The SDK SHALL NOT implement retry functionality of any kind.

#### Scenario: 429 falls through to UnexpectedStatusCodeException

- **WHEN** the API returns 429
- **THEN** the client throws `UnexpectedStatusCodeException`
- **AND** consumers can still read `$e->getResponse()->getHeaderLine('Retry-After')` directly if needed

#### Scenario: RateLimitException class does not exist

- **WHEN** the package's `src/Exception/` directory is inspected
- **THEN** there is no `RateLimitException` class

### Requirement: JSON parse failures during mapping carry request context

When a response body cannot be JSON-decoded during DTO mapping in the endpoint layer, the thrown exception's message MUST include the request method and URI (sourced from `Client::getLastRequest()`) and an excerpt of the failing body, so consumers can diagnose without needing a separate logger.

#### Scenario: JSON parse failure message

- **WHEN** an endpoint receives a response whose body is not valid JSON
- **THEN** the exception that propagates carries a message containing the request method (e.g. `GET`), the request URI, and an excerpt of the response body

### Requirement: Status-code dispatch lives in the client, not in the exception classes

The mapping from status code to exception type SHALL be centralized in `Client::assertStatusCode()` (or a single helper called from `Client::request()`). Static `::assert()` factory methods on exception classes MUST NOT be used as the dispatch mechanism.

#### Scenario: No assert() factory used

- **WHEN** the package source under `src/Exception/` is inspected
- **THEN** no concrete exception class exposes a `static function assert(ResponseInterface $response)` method that participates in status-code dispatch
