## Why

The SDK has organically grown to a point where several rough edges are now obvious: pagination forces consumers into manual loop bookkeeping, DTOs silently drop fields the SDK hasn't typed yet, exceptions stringify the response body instead of exposing e-conomic's structured error fields, and several internal abstractions (`Query`, endpoint interfaces, `LoggerAwareInterface`) earn very little. The upcoming v2 major release is the right moment to fix these together rather than ship a long string of breaking minors.

## What Changes

- **BREAKING** Restructure resource endpoints into a dispatcher / leaf-sub-endpoint shape that mirrors the API path tree. `OrdersEndpoint` and `InvoicesEndpoint` become dispatchers that lazily expose state-specific sub-endpoints. Every collection-returning leaf sub-endpoint has the same three-method shape:
  ```
  $client->products()->getPage()                 → Collection<Product>
  $client->products()->getByNumber('5')          → ?Product
  $client->products()->paginate()                → \Generator<Product>

  $client->orders()->drafts()->getPage()         → Collection<Order>
  $client->orders()->drafts()->getByNumber(5)    → ?Order
  $client->orders()->drafts()->paginate()        → \Generator<Order>

  $client->orders()->sent()->getPage()           → Collection<Order>
  $client->orders()->sent()->getByNumber(5)      → ?Order
  $client->orders()->sent()->paginate()          → \Generator<Order>

  $client->invoices()->booked()->getPage()       → Collection<BookedInvoice>
  $client->invoices()->booked()->getByNumber(5)  → ?BookedInvoice
  $client->invoices()->booked()->paginate()      → \Generator<BookedInvoice>
  ```
  Renames `get`/`getDraft`/`getSent`/`getBooked` (collection methods) to `getPage()` on the corresponding leaf sub-endpoint. Renames `getDraftByNumber`/`getSentByNumber`/`getBookedByNumber` to `getByNumber()` on the corresponding leaf sub-endpoint. Adds `paginate()` on every leaf collection sub-endpoint — walks all pages via server-provided `pagination.nextPage.url`. Consumers stop incrementing `skipPages` themselves.
- Introduce `CollectionEndpoint<T>` abstract base (extends `Endpoint`) that provides `paginate()` once. Leaf collection sub-endpoints extend `CollectionEndpoint<T>` and implement abstract `getPage()` and protected `fetchPageByUrl()`. Non-collection endpoints (dispatchers like `OrdersEndpoint`, single-resource leaves like `SelfEndpoint`) extend `Endpoint` directly.
- **BREAKING** Drop `paginate()` from `Collection<T>`. The class becomes a passive data carrier (`collection`, `pagination`, `raw`). No fetcher closure, no `setFetcher()`, no `LogicException` for hand-constructed Collections.
- **BREAKING** Migrate `Order`, `Line`, and `BookedInvoice` from mutable property-bag DTOs to `final` classes with `readonly` constructor-promoted fields, matching the rest of the Response namespace.
- **BREAKING** Add `public array $raw = []` to each entry-point Response DTO (`Product`, `Order`, `BookedInvoice`, `Collection`). Populated by the endpoint after Valinor mapping. Nested DTOs (`Inventory`, `Line`, `Pagination`, `Page`) do NOT get `$raw` — they are reachable via the parent's `$raw`.
- Stamp a `User-Agent` header (`Setono-Economic-PHP/<version> (+url)`) on every outgoing request. Version resolved at runtime via `Composer\InstalledVersions`.
- **BREAKING** Delete the `Query` class. `Client::get()` takes `array<string, scalar|null>` directly. `CollectionRequestOptions::asQuery()` becomes `toArray()`.
- **BREAKING** Restructure the exception hierarchy:
  - Add `EconomicException` marker interface.
  - Add `ClientErrorException` (4xx base) and `ServerErrorException` (5xx base).
  - Add `UnauthorizedException` (401), `ForbiddenException` (403), `ValidationException` (400/422), `MethodNotAllowedException` (405), `NotImplementedException` (501). (No `RateLimitException` — e-conomic's docs never list 429 in their HTTP status codes; if we ever encounter it, it'll surface as `UnexpectedStatusCodeException`.)
  - All response-aware exceptions lazily parse the JSON body and expose `errorCode`, `message`, `developerHint`, `logId`, `logTime`, and `validationErrors` via getters. `getValidationErrors()` returns the **raw nested document** that mirrors e-conomic's wire format (not a flattened list) — consumers walk it themselves.
- **BREAKING** Delete `ProductsEndpointInterface`, `OrdersEndpointInterface`, `InvoicesEndpointInterface`, and the base `EndpointInterface`. Keep `ClientInterface` (used for downstream testability).
- **BREAKING** Drop the logger entirely. Remove `psr/log` from `require`, remove `LoggerAwareInterface` from `Client` and `Endpoint`, and bake the JSON-parse error context directly into the thrown exception. Request/response logging belongs in the consumer-supplied PSR-18 client, not in the SDK.
- Extend `Client::get()` to accept absolute URLs in addition to relative paths, so the pagination walker can follow server-provided `nextPage.url` values via the same method. Absolute URLs that don't match the e-conomic base host are rejected to prevent leaking auth credentials.
- Add `Client::self(): SelfEndpoint` returning a `SelfEndpoint` lazily, consistent with `products()`, `orders()`, `invoices()`. `SelfEndpoint::get(): Self_` performs `GET /self`, maps the response, memoizes the result inside the endpoint, and returns the typed DTO. The endpoint shape leaves room for `SelfEndpoint::putUser()`, `putCompany()`, etc. when write operations land in v3. `Self_` is an entry-point DTO (extends `Resource`, has `$raw`).
- Cap `CollectionRequestOptions::$pageSize` at 1000 (e-conomic's server maximum) via `Webmozart\Assert\Assert::lessThanEq($pageSize, 1000)`. Prevents server-side rejection.

## Capabilities

### New Capabilities

- `http-transport`: Client construction, authentication header stamping, User-Agent header, request/response lifecycle, and status-code-to-exception mapping. Owns `Client` and `ClientInterface`.
- `endpoint-api`: Typed endpoint methods (single-item lookup, collection fetch, pagination generator), Valinor-based DTO mapping, and the `$raw` escape hatch. Owns the `Endpoint` base class and the three concrete endpoints, plus the Response DTOs.
- `error-handling`: Exception hierarchy from `EconomicException` marker down through 4xx/5xx bases and their concrete subclasses. Owns lazy parsing of e-conomic's structured error response shape.

### Modified Capabilities

<!-- No prior specs exist; this is the first OpenSpec change in the repo. -->

## Impact

- **Code**: Every file under `src/` is touched. Several files deleted (the three resource endpoint interfaces, `EndpointInterface`, `Query`). Multiple new exception classes added. The endpoint hierarchy is restructured: `CollectionEndpoint<T>` abstract base added; `OrdersEndpoint` and `InvoicesEndpoint` become dispatchers; new leaf sub-endpoint classes added (`DraftOrdersEndpoint`, `SentOrdersEndpoint`, `BookedInvoicesEndpoint`). The `Response/` DTOs gain a `$raw` field on entry-point types.
- **Public API**: BREAKING across the board. The constructor signature of `Client` does not change, but the surrounding contract does: `Query` removed, endpoint interfaces removed, exception hierarchy reshaped, DTOs reshaped. Released as a new major version.
- **Dependencies**: `psr/log` removed from `require`. No new runtime dependencies. `Composer\InstalledVersions` is already provided by Composer itself.
- **Build**: `rector.php` skip list extended to exclude `ReadOnlyClassRector` for the Response DTOs that own a mutable `$raw` property.
- **README**: Pagination example rewritten to use the generator; error-handling section added to document the new exception tree.
