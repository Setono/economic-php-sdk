## 1. Dependency and config groundwork

- [x] 1.1 Remove `psr/log` from `composer.json` `require`
- [x] 1.2 Run `composer update` and confirm lock resolves
- [x] 1.3 Extend `rector.php` `skip` list to exclude `ReadOnlyClassRector` for the entry-point Response DTOs (`Product`, `Order`, `BookedInvoice`, `Collection`) so the new mutable `$raw` field isn't reverted

## 2. Logger removal

- [x] 2.1 Remove `LoggerAwareInterface`, the `$logger` field, the `setLogger()` method, and the `Psr\Log\*` imports from `src/Client/Client.php`
- [x] 2.2 Remove the `setLogger()` propagation calls from `Client::invoices()`, `Client::orders()`, `Client::products()`
- [x] 2.3 Remove `LoggerAwareInterface`, the `$logger` field, the `setLogger()` method, and the `NullLogger` default from `src/Client/Endpoint/Endpoint.php`
- [x] 2.4 In `Endpoint::createSourceFromResponse()`, drop the `$this->logger->error(...)` call; rebuild the existing error context (method, URI, body excerpt) directly into the thrown exception's message
- [x] 2.5 If `src/Client/Endpoint/EndpointInterface.php` exists, inspect it; if it only contained `LoggerAwareInterface` re-exports or is empty, delete the file

## 3. Drop `Query` and simplify request options

- [x] 3.1 Delete `src/Request/Query.php` and `tests/Request/QueryTest.php`
- [x] 3.2 Update `Client::get()` signature to `get(string $uri, array $query = []): ResponseInterface` (drop the `Query|array` union)
- [x] 3.3 Inline `http_build_query(..., '', '&', PHP_QUERY_RFC3986)` in `Client::get()` and inline the empty-array check (no separate class needed)
- [x] 3.4 Update `ClientInterface::get()` docblock + signature to match
- [x] 3.5 Rename `CollectionRequestOptions::asQuery()` to `toArray()`, returning `array<string, scalar|null>` (drop the `Query` instantiation)
- [x] 3.6 Update all endpoint call sites (`InvoicesEndpoint`, `OrdersEndpoint`, `ProductsEndpoint`) to use `toArray()` instead of `asQuery()`
- [x] 3.7 Add `Assert::lessThanEq($pageSize, 1000)` to the `CollectionRequestOptions` constructor (e-conomic's server maximum)
- [x] 3.8 Update `tests/Request/CollectionRequestOptionsTest.php` to assert `toArray()` shape AND that constructing with `pageSize: 1001` throws `\InvalidArgumentException`

## 4. Endpoint restructuring (option C): remove interfaces, add CollectionEndpoint<T>, split dispatchers from leaves

- [x] 4.1 Delete `src/Client/Endpoint/ProductsEndpointInterface.php`
- [x] 4.2 Delete `src/Client/Endpoint/OrdersEndpointInterface.php`
- [x] 4.3 Delete `src/Client/Endpoint/InvoicesEndpointInterface.php`
- [x] 4.4 Remove the `implements *EndpointInterface` from each concrete endpoint class
- [x] 4.5 Create `src/Client/Endpoint/CollectionEndpoint.php` — abstract `CollectionEndpoint extends Endpoint` with PHPDoc `@template T`. Declare `abstract public function getPage(?CollectionRequestOptions $opts = null): Collection;` and `abstract protected function fetchPageByUrl(string $url): Collection;`. Provide concrete `public function paginate(?CollectionRequestOptions $opts = null): \Generator` whose body is: fetch first page via `getPage($opts)`, then loop: `yield from $page->collection`, follow `nextPage.url` via `fetchPageByUrl`, stop when null
- [x] 4.6 Refactor `src/Client/Endpoint/ProductsEndpoint.php` to extend `CollectionEndpoint` (with PHPDoc `@template-extends CollectionEndpoint<Product>`). Rename `get()` → `getPage()`. Add protected `fetchPageByUrl(string $url): Collection` that uses `Client::getUrl()` + `decodeResponse()` + Valinor and stamps `$raw`. `getByNumber` stays as-is.
- [x] 4.7 Convert `src/Client/Endpoint/OrdersEndpoint.php` into a dispatcher: extend plain `Endpoint`, drop `getDraft*`/`getSent*` methods. Add lazy memoized accessors `drafts(): DraftOrdersEndpoint` and `sent(): SentOrdersEndpoint`
- [x] 4.8 Create `src/Client/Endpoint/Orders/DraftOrdersEndpoint.php` extending `CollectionEndpoint` (`@template-extends CollectionEndpoint<Order>`). Implement `getPage(?CollectionRequestOptions $opts = null)` against `GET /orders/drafts`; `getByNumber(int $n): ?Order` against `GET /orders/drafts/:n` (returns null on 404); protected `fetchPageByUrl(string $url)` for pagination
- [x] 4.9 Create `src/Client/Endpoint/Orders/SentOrdersEndpoint.php` extending `CollectionEndpoint<Order>`. Implement `getPage` against `GET /orders/sent`; `getByNumber(int $n)` against `GET /orders/sent/:n`; `fetchPageByUrl`
- [x] 4.10 Convert `src/Client/Endpoint/InvoicesEndpoint.php` into a dispatcher: drop `getBooked*` methods. Add `booked(): BookedInvoicesEndpoint` lazy accessor
- [x] 4.11 Create `src/Client/Endpoint/Invoices/BookedInvoicesEndpoint.php` extending `CollectionEndpoint<BookedInvoice>`. Implement `getPage` against `GET /invoices/booked`; `getByNumber(int $n)` against `GET /invoices/booked/:n`; `fetchPageByUrl`
- [x] 4.12 Update `Client::products()`, `Client::orders()`, `Client::invoices()` return types to the concrete dispatcher / leaf classes
- [x] 4.13 Update the corresponding return types in `ClientInterface`

## 5. `User-Agent` header

- [x] 5.1 Add a private `userAgent()` helper on `Client` that returns the formatted string `Setono-Economic-PHP/<version> (+https://github.com/Setono/economic-php-sdk)`, resolving `<version>` via `\Composer\InstalledVersions::getVersion('setono/economic-php-sdk')` with a `dev` fallback when null
- [x] 5.2 Apply `withHeader('User-Agent', $this->userAgent())` in `Client::request()` alongside the existing auth + content-type headers
- [x] 5.3 Update `tests/Client/ClientTest::it_sends_expected_request` (or add a new test) to assert the `User-Agent` header is present and matches the expected prefix

## 6. `Client::getUrl()` for absolute URLs

- [x] 6.1 Add `public function getUrl(string $absoluteUrl): ResponseInterface` on `Client` that builds a PSR-7 GET request for the absolute URL and dispatches through `Client::request()`
- [x] 6.2 Add the matching method to `ClientInterface`
- [x] 6.3 Add a test confirming `getUrl()` does NOT prepend the base URI and still applies auth + `User-Agent`

## 7. Exception hierarchy

- [x] 7.1 Create `src/Exception/EconomicException.php` as an empty marker interface
- [x] 7.2 Update `src/Exception/ResponseAwareException.php` to `implements EconomicException`, add lazy-parsing `getErrorCode()`, `getDeveloperHint()`, `getLogId()`, `getLogTime(): ?\DateTimeImmutable`, `getValidationErrors(): array<string, mixed>` getters with memoization; ensure malformed JSON does not throw. `getValidationErrors()` returns the raw nested `errors` value verbatim (NOT flattened) — see error-handling spec for shape
- [x] 7.3 Create abstract `src/Exception/ClientErrorException.php` extending `ResponseAwareException` (4xx base)
- [x] 7.4 Create abstract `src/Exception/ServerErrorException.php` extending `ResponseAwareException` (5xx base)
- [x] 7.5 Change `src/Exception/NotFoundException.php` to `extends ClientErrorException`; remove its static `assert()` factory
- [x] 7.6 Change `src/Exception/InternalServerErrorException.php` to `extends ServerErrorException`; remove its static `assert()` factory
- [x] 7.7 Create `src/Exception/UnauthorizedException.php` (401) extending `ClientErrorException`
- [x] 7.8 Create `src/Exception/ForbiddenException.php` (403) extending `ClientErrorException`
- [x] 7.9 Create `src/Exception/ValidationException.php` (400+422) extending `ClientErrorException`
- [x] 7.10 Create `src/Exception/RateLimitException.php` (429) extending `ClientErrorException`. Do NOT add a `retryAfter()` accessor or any other method that parses the `Retry-After` header; the class exists purely as a typed catch target
- [x] 7.11 Create `src/Exception/MethodNotAllowedException.php` (405) extending `ClientErrorException`
- [x] 7.12 Create `src/Exception/NotImplementedException.php` (501) extending `ServerErrorException`
- [x] 7.13 Ensure `UnexpectedStatusCodeException` still exists and extends `ResponseAwareException` (catch-all for non-2xx codes not matched above)
- [x] 7.14 Rewrite `Client::assertStatusCode()` to dispatch on the status code: 401→Unauthorized, 403→Forbidden, 404→NotFound, 405→MethodNotAllowed, 400|422→Validation, 429→RateLimit, 500→InternalServerError, 501→NotImplemented, other non-2xx (including 415)→UnexpectedStatusCode
- [x] 7.15 Add unit tests covering: each concrete exception thrown for its code (401, 403, 404, 405, 400, 422, 429, 500, 501, 415, 502); lazy parsing of `errorCode`/`developerHint`/`logId`/`logTime`/validation `errors`; getters returning `null`/`[]` for malformed/empty body; `logTime` parsing for valid ISO-8601 and `null` for malformed
- [x] 7.16 Add a test that asserts every concrete exception in `src/Exception/` implements `EconomicException`
- [x] 7.17 Add a test asserting `RateLimitException` does NOT declare a `retryAfter()` method (guards against accidental future addition)
- [x] 7.18 Add a test asserting `getValidationErrors()` returns the raw nested document when the body contains a nested `errors` structure with array indices

## 8. Response DTO migration (entry-point `$raw` + readonly typed fields)

- [x] 8.1 Create `src/Response/Resource.php` — abstract base class declaring `public array $raw = []` (with `array<string, mixed>` PHPDoc)
- [x] 8.2 Migrate `src/Response/Product/Product.php` to extend `Resource`, use `final class` (NOT `final readonly class`), and have all typed fields as `public readonly` constructor-promoted params
- [x] 8.3 Migrate `src/Response/Order/Order.php` to extend `Resource`; convert mutable `public ?int $orderNumber` and `public array $lines` to readonly constructor-promoted (`$lines` defaults to `[]`)
- [x] 8.4 Migrate `src/Response/Invoice/BookedInvoice.php` to extend `Resource`; convert mutable `public ?int $bookedInvoiceNumber` to readonly constructor-promoted
- [x] 8.5 Migrate `src/Response/Line/Line.php` to readonly constructor-promoted (does NOT extend `Resource` — nested only)
- [x] 8.6 Confirm `Inventory`, `Pagination`, `Page` stay as they are (already readonly, nested-only)
- [x] 8.7 Migrate `src/Response/Collection/Collection.php` to extend `Resource`; keep `public readonly array $collection` and `public readonly Pagination $pagination`; class is `final` (not `final readonly`). Collection MUST remain a passive data carrier — no `$fetcher` property, no `setFetcher()` method, no `paginate()` method
- [x] 8.8 In every leaf collection sub-endpoint method that returns a DTO (`getPage`, `getByNumber`, `fetchPageByUrl`) and in `SelfEndpoint::get`, after Valinor maps the response, assign `$dto->raw = $decodedJson` before returning
- [x] 8.9 Replace `Endpoint::createSourceFromResponse()` with `Endpoint::decodeResponse(ResponseInterface): array` — call `json_decode($body, true, flags: JSON_THROW_ON_ERROR)`, throw a `\RuntimeException` on `JsonException` with the request method, URI (from `Client::getLastRequest()`), and a body excerpt baked into the message. Assert the decoded value is an array. Endpoint callers then do `Source::array($data)` to hand it to Valinor

## 9. Pagination tests

(Pagination implementation is split across section 4 — `CollectionEndpoint::paginate()` is implemented once in 4.5; each leaf sub-endpoint's `fetchPageByUrl()` is implemented in 4.6 / 4.8 / 4.9 / 4.11. This section is just the test coverage.)

- [x] 9.1 Add a `paginate()` integration test for `ProductsEndpoint` using a fake PSR-18 client keyed by URL. Cover: walks three pages (3 GETs total: `products`, then two `nextPage.url` GETs); request options applied to first page only; subsequent pages use the server-provided URL verbatim
- [x] 9.2 Add a `paginate()` test for `DraftOrdersEndpoint` covering: stops when `nextPage` is null after page 1 (only 1 GET); single empty page yields nothing
- [x] 9.3 Add a `paginate()` test for `SentOrdersEndpoint` covering the same single-page case (smoke test that both Orders sub-endpoints share the inherited walker)
- [x] 9.4 Add a `paginate()` test for `BookedInvoicesEndpoint` covering a two-page walk
- [x] 9.5 Add a unit test that asserts every leaf collection sub-endpoint inherits `paginate()` from `CollectionEndpoint` (i.e. `(new ReflectionMethod($leaf, 'paginate'))->getDeclaringClass()->getName() === CollectionEndpoint::class`). Guards against accidental override in a leaf class
- [x] 9.6 Add a unit test that asserts `Collection` has no `paginate`, no `setFetcher`, and no `$fetcher` property (guards against the prior design accidentally returning)

## 9b. `Self` endpoint

- [x] 9b.1 Create `src/Response/Self_/Self_.php` — DTO class. Use `Self_` (trailing underscore; standard PHP convention for the reserved word). Extend `Resource`; declare typed fields for the JSON returned by `GET /self` (start with whatever fields seem stable; rely on `$raw` for the rest)
- [x] 9b.2 Create `src/Client/Endpoint/SelfEndpoint.php` extending `Endpoint`. Add `public function get(): Self_` that issues `GET /self` via `$this->client->get('self')`, decodes via `decodeResponse()`, maps via Valinor, assigns `$raw`, memoizes the result in a private `?Self_ $cached` field, and returns it on every subsequent call. Leave room (docblock comment is enough) for future `putUser()`, `putCompany()`, etc.
- [x] 9b.3 Add `Client::self(): SelfEndpoint` — lazy accessor mirroring `products()`/`orders()`/`invoices()`. Same instance returned on every call
- [x] 9b.4 Add `self()` to `ClientInterface`
- [x] 9b.5 Add tests covering: `$client->self()` is the same `SelfEndpoint` on repeated calls and triggers no HTTP; `$client->self()->get()` makes one HTTP request on first call and returns the same DTO on second call with zero additional requests; the returned DTO has `$raw` populated

## 10. Update existing tests for the new contract

- [x] 10.1 `tests/Client/ClientTest.php` — update `it_sends_expected_request` for the new `get()` signature (array, not `Query|array`); add `User-Agent` header assertion
- [x] 10.2 Rename or restructure tests that assert against `$client->orders()` returning an interface — assert the concrete dispatcher class (`OrdersEndpoint`). Add tests that `$client->orders()->drafts()` returns `DraftOrdersEndpoint` and `$client->orders()->sent()` returns `SentOrdersEndpoint`, both idempotent
- [x] 10.3 Update existing `NotFoundExceptionTest` and `InternalServerErrorExceptionTest` to reflect that `assert()` factories are gone — the dispatch is the `Client`'s job, not the exception's
- [x] 10.4 Add a `Resource`-aware test: map a DTO with extra JSON fields, assert typed fields are populated AND `$raw` contains the full decoded body including the extras
- [x] 10.5 Add tests for the new `getPage()` / `getByNumber()` names on each leaf sub-endpoint, verifying the outgoing request URL matches the expected API path (`products`, `orders/drafts`, `orders/sent`, `invoices/booked`)

## 11. README v2 migration section

- [x] 11.1 Rewrite the README's "Paginate" example to use `foreach ($client->products()->paginate() as $product) {}` (and add an equivalent example with filter/sortBy options on `paginate()`). Also show the single-page form `$client->products()->getPage(new CollectionRequestOptions(...))`.
- [x] 11.2 Add a "v2 migration" section documenting: dispatcher/leaf endpoint restructuring (`getDraft` → `drafts()->getPage()`, `getDraftByNumber` → `drafts()->getByNumber()`, etc.); `Query` removed → pass arrays; `*EndpointInterface` removed → type against concrete; `asQuery()` → `toArray()`; `setLogger()` removed → log via wrapped PSR-18 client; new exception hierarchy with examples
- [x] 11.3 Add an "Error handling" section showing `try { ... } catch (EconomicException $e) { $e->getLogId(); $e->getValidationErrors(); }`. Mention that `getValidationErrors()` returns the raw nested document and link to e-conomic's docs for the shape
- [x] 11.4 Add a brief "Raw access" note explaining `$dto->raw` and that nested DTOs are reached via the parent's `$raw`
- [x] 11.5 Add a short "Ping / who am I" example using `$client->self()->get()`

## 12. CLAUDE.md update

- [x] 12.1 Update the architecture section in `CLAUDE.md` to reflect: no `Query` class; no endpoint interfaces; no logger; `$raw` on entry-point DTOs; new exception tree (incl. 405/501); `User-Agent` header; `Client::getUrl()` exists; `$client->self()` returns a `SelfEndpoint` (same pattern as other accessors) whose `get()` is memoized; **the new endpoint hierarchy: dispatchers (`OrdersEndpoint`, `InvoicesEndpoint`) hold no collection methods themselves; leaf collection sub-endpoints extend `CollectionEndpoint<T>` and share the inherited `paginate()` walker; method shape on every leaf is `getPage` / `getByNumber` / `paginate`**
- [x] 12.2 Add a "URL construction policy" note to CLAUDE.md: lookups by ID (`products/:n`) deliberately concatenate; pagination + future related-resource navigation deliberately follow server-provided self-links. Do NOT "fix" the concatenation — it's a considered trade-off vs the extra round-trip pure HATEOAS would require

## 13. Final validation

- [x] 13.1 Run `composer analyse` — must pass with zero PHPStan errors
- [x] 13.2 Run `composer phpunit` — must pass with zero deprecations
- [x] 13.3 Run `composer check-style` — must pass with zero ECS findings
- [x] 13.4 Run `vendor/bin/rector --dry-run` — must report zero changes
- [x] 13.5 Bump `composer.json` package version branch alias to `2.x-dev` if applicable; ensure no `1.x`-specific references remain

## 14. Open question resolution

- [x] 14.1 Confirm `Collection::$raw` contains the entire response envelope (not just `collection`/`pagination`) — implement that way and document in PHPDoc
- [x] 14.2 Read `src/Client/Endpoint/EndpointInterface.php` before deleting it (task 2.5): if it carries shared contract beyond `LoggerAwareInterface`, decide whether to keep that contract on the abstract `Endpoint` base instead
