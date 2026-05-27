# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP SDK for the [e-conomic REST API](https://restdocs.e-conomic.com/). Library — not an application. Requires PHP >= 8.4. Installed by consumers as `setono/economic-php-sdk`.

## Commands

Composer scripts (run via `composer <script>` or the global aliases in `~/.zshrc`):

- `composer phpunit` — run the PHPUnit test suite (`tests/`)
- `composer analyse` — PHPStan at `level: max`, configured via `phpstan.neon.dist`. PHPStan extensions (`phpstan-phpunit`, `phpstan-webmozart-assert`, `phpstan-strict-rules`) are auto-wired via `phpstan/extension-installer`.
- `composer check-style` / `composer fix-style` — ECS (sylius-labs coding standard) check / autofix
- `vendor/bin/rector` (or `--dry-run`) — Rector code modernization, configured via `rector.php` (LevelSetList::UP_TO_PHP_84). CI runs `--dry-run` in the Coding Standards job.
- `vendor/bin/infection` — mutation testing (configured via `infection.json.dist`; min MSI 53.85, min covered MSI 79.25)

Run a single test: `vendor/bin/phpunit --filter <TestName>` or `vendor/bin/phpunit tests/Path/To/SomeTest.php`.

CI matrix (`.github/workflows/build.yaml`) runs PHP 8.4 against both `lowest` and `highest` Composer dependency resolutions — when changing dependency constraints, mentally check both ends still work.

## Architecture

Three layers, kept deliberately thin:

**1. `Client` (`src/Client/Client.php`)** — the user-facing entrypoint. Constructed with `(appSecretToken, agreementGrantToken)` plus four optional named collaborators: `httpClient` (PSR-18 `ClientInterface`), `requestFactory` (PSR-17 `RequestFactoryInterface`), `streamFactory` (PSR-17 `StreamFactoryInterface`, pre-wired for forthcoming write endpoints but currently unread internally), and `mapperBuilder` (Valinor `MapperBuilder`). Any collaborator left as `null` is resolved eagerly inside `__construct` via `php-http/discovery` (`Psr18ClientDiscovery::find()`, `Psr17FactoryDiscovery::findRequestFactory()`, `Psr17FactoryDiscovery::findStreamFactory()`); the `MapperBuilder` falls back to `Client::defaultMapperBuilder()`. The resolved collaborators are stored on `private readonly` properties — there are **no setters**, the client is immutable after construction. The auth tokens become the `X-AppSecretToken` / `X-AgreementGrantToken` headers on every request, alongside a `User-Agent` (`Setono-Economic-PHP/<version> (+url)`, version resolved at runtime via `Composer\InstalledVersions`). Exposes lazy accessors (`products()`, `orders()`, `invoices()`, `self()`), a low-level `request(RequestInterface): ResponseInterface` escape hatch, plus one JSON-aware helper: `get(string $uri, array $query = []): array`. `get()` accepts either a relative path (`'products'`) or a fully-qualified URL pointing at the e-conomic API host (e.g. a `pagination.nextPage.url`), detected by the `https?://` prefix. It decodes the response body internally and returns `array<string, mixed>`, NOT `ResponseInterface`. Absolute URLs are validated against the SDK's base host — `get()` throws `\InvalidArgumentException` before dispatch if the host differs, so auth credentials never leak to a non-e-conomic host. Combining an absolute URL with a non-empty `$query` is also rejected. For non-JSON endpoints (PDFs, attachment files) consumers fall back to `request()`. Base URI is hardcoded to `https://restapi.e-conomic.com`. There is no logger — `Client` does NOT implement `LoggerAwareInterface` and `psr/log` is NOT a dependency. Non-2xx responses funnel through `assertStatusCode()` which dispatches by status code into the exception hierarchy below.

**2. Endpoint hierarchy (`src/Client/Endpoint/`)** — three abstract tiers plus concrete leaves and dispatchers:

- `Endpoint` (abstract base): holds `$client` and `$mapperBuilder`. Nothing else. Dispatchers stop here.
- `ResourceEndpoint<T of Resource> extends Endpoint` (abstract): declares the per-resource hints `getPath(): string` and `getItemClass(): class-string<T>` (both `static`). Provides the shared `getOne(int|string|null $id = null): T` pipeline (GET → decode → Valinor map → `$raw` stamp). With `$id = null` it fetches `getPath()`; with `$id` it fetches `"{getPath()}/{$id}"`.
- `CollectionEndpoint<T> extends ResourceEndpoint<T>` (abstract): adds `getItem(int|string $id): ?T` (wraps `getOne` with 404→null), plus concrete `getPage(?CollectionRequestOptions): Collection<T>` and `paginate(?CollectionRequestOptions): \Generator<T>` (every leaf inherits these unchanged).
- Concrete classes:
  - **Leaf collection endpoints** (`ProductsEndpoint`, `Orders/DraftOrdersEndpoint`, `Orders/SentOrdersEndpoint`, `Invoices/BookedInvoicesEndpoint`) extend `CollectionEndpoint<T>`. Public surface: `getPage()` / `getByNumber()` / `paginate()`. `getByNumber()` is a one-liner: `return $this->getItem($number);`. Each leaf only declares the two static hints plus the typed public lookup method.
  - **Single-resource leaves** (`SelfEndpoint`) extend `ResourceEndpoint<Self_>` directly (NOT `CollectionEndpoint` — Self has no by-id or pagination semantics). `SelfEndpoint::get()` calls `$this->getOne()` and memoizes so `$client->self()->get()` twice → one HTTP request.
  - **Dispatcher endpoints** (`OrdersEndpoint`, `InvoicesEndpoint`) extend plain `Endpoint`. They own NO collection methods. They expose lazy memoized accessors to their leaf sub-endpoints (`drafts()`, `sent()`, `booked()`).

There are **no endpoint interfaces** — the concrete classes are `final` and consumers test against them by constructing `Client` with a fake PSR-18 client via the `httpClient:` named argument (`tests/TestDouble/ScriptedHttpClient.php` is the project's URL-keyed fake). `ClientInterface` is kept because downstream consumers want to mock the client itself.

**3. Exception hierarchy (`src/Exception/`)** — `EconomicException` (marker interface, implemented by every SDK throw) → `ResponseAwareException` (abstract; carries the PSR response, lazy-parses `errorCode`/`developerHint`/`logId`/`logTime`/`getValidationErrors()` from e-conomic's JSON body) → `ClientErrorException` (4xx) and `ServerErrorException` (5xx) abstract bases → concrete types: `UnauthorizedException` (401), `ForbiddenException` (403), `NotFoundException` (404), `MethodNotAllowedException` (405), `ValidationException` (400/422), `InternalServerErrorException` (500), `NotImplementedException` (501). `UnexpectedStatusCodeException` is the catch-all for non-2xx codes not matched above (415, 429, 502, 504, etc. — e-conomic's docs never list 429, so it deliberately has no named subclass). `MalformedResponseException` (also extends `ResponseAwareException`) is thrown for 2xx responses whose body isn't the JSON object the SDK expects. `InvalidUrlException` (extends `\InvalidArgumentException`, implements `EconomicException`) is the only pre-flight exception — thrown by `Client::get()` when an absolute URL points to a foreign host or is combined with `$query`. The SDK does NOT implement retry semantics. `getValidationErrors()` returns e-conomic's raw nested document verbatim, NOT a flattened list.

**4. Response DTOs (`src/Response/`)** — entry-point DTOs (`Product`, `Order`, `BookedInvoice`, `Self_`, `Collection<T>`) extend `Resource` and carry `public array $raw` populated by the endpoint after Valinor maps the typed fields. These DTOs are `final class` (NOT `final readonly class`) so `$raw` can be assigned after construction — `rector.php` skips `ReadOnlyClassRector` for them. Typed fields are still `public readonly` constructor-promoted. Nested DTOs (`Inventory`, `Line`, `Pagination`, `Page`) do NOT carry `$raw` — they're reachable through the parent's `$raw['nested-key']`. `Collection<T>` is a passive data carrier (collection + pagination + raw); pagination logic lives on `CollectionEndpoint<T>`, never on `Collection<T>`. Valinor is configured with `enableFlexibleCasting()` and `allowSuperfluousKeys()`. **`Self_` uses the trailing-underscore PHP convention** because `Self` is a reserved word.

**5. Request helpers (`src/Request/`)** — `CollectionRequestOptions` is an immutable final-readonly options DTO (skipPages, pageSize, filter, sortBy) with `withX()` builders and a `toArray(): array<string, scalar|null>` projection. `pageSize` is asserted `>= 1 AND <= 1000` (e-conomic's server maximum). The previous `Query` class is removed — `Client::get()` takes an `array` directly.

## URL construction policy

Lookups by ID (`products/:n`, `orders/drafts/:n`) deliberately concatenate via `sprintf`. Pagination follows the server-provided `pagination.nextPage.url`. Do NOT "fix" the concatenation to be pure HATEOAS — the e-conomic docs say "never concatenate any urls" but pure HATEOAS for direct lookups requires an extra `GET /` round-trip to discover URI templates, which is overkill for "fetch product 5." The trade-off is documented in `openspec/changes/v2-sdk-redesign/design.md`.

## Testing

All new code requires unit test coverage — no exceptions. Use [Prophecy](https://github.com/phpspec/prophecy) for mocks; do NOT use PHPUnit's built-in `createMock()` / `getMockBuilder()`. Prophecy is already wired in via `phpspec/prophecy-phpunit` (the `ProphecyTrait` for test classes) and `jangregor/phpstan-prophecy` (the PHPStan extension that teaches level-max about prophecy's revealed objects).

## Performance note for consumers

Valinor mapping is expensive without a cache. The README documents that consumers should pass a pre-configured `MapperBuilder` with `FileSystemCache` into the `Client` constructor (via the `mapperBuilder:` named argument) for production use. When changing the default `MapperBuilder` config in `Client::defaultMapperBuilder()`, remember that consumers may be supplying their own builder and won't pick up changes there.
