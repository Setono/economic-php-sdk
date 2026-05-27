## Context

The current SDK (1.x) hits the e-conomic REST API through PSR-18/17, with Valinor mapping JSON responses into typed DTOs. After several iterations the surface has accumulated decisions that don't pull their weight: a wrapper `Query` class that adds three lines of behavior; per-endpoint interfaces that 100% mirror their concrete classes; `psr/log` integration that exists for one call site that immediately re-throws; a pagination convention that pushes `skipPages++` onto every consumer; DTOs that silently drop fields not yet covered by the type definitions; and a flat exception hierarchy that stringifies e-conomic's structured error JSON into a single message.

The user is shipping a v2 major release, so backwards-compatibility shims are explicitly not in scope. The redesign rolls all of these changes into one coherent break.

Stakeholders: the SDK author (drives the release), downstream consumers of `setono/economic-php-sdk` (need a clean migration story in the README).

## Goals / Non-Goals

**Goals:**

- Make pagination feel native to PHP — iteration, not loop bookkeeping.
- Give consumers a typed-where-possible, raw-when-not story for every field e-conomic returns, without forcing PRs for trivial field additions.
- Surface e-conomic's structured error fields (`errorCode`, `developerHint`, `logId`, validation `errors[]`) on exceptions, lazily parsed.
- Identify the SDK to the API via `User-Agent`.
- Remove abstractions that aren't earning their weight: `Query`, endpoint interfaces, the logger plumbing.
- Keep the PSR-18/17 + Valinor architecture untouched. Don't bolt on middleware, retry, or rate limiting in this change (explicitly out of scope per prior discussion).

**Non-Goals:**

- Middleware/plugin pipeline — explicitly rejected.
- Auth as a swappable concern (`AuthInterface`) — out of scope for v2.
- Async / concurrent requests.
- Rate limiting, retries, idempotency keys — explicitly out of scope. No retry mechanism is implemented anywhere in the SDK, and exception classes do not expose retry-shaped accessors (e.g. `retryAfter()`).
- Coverage expansion to additional e-conomic endpoints (customers, accounts, etc.). v2 is a redesign of existing surface, not a coverage push.
- Webhook helpers, bulk operations.
- OpenAPI-based DTO generation.

## Decisions

### Endpoint structure: dispatcher endpoints + ResourceEndpoint hierarchy

The v2 endpoint hierarchy mirrors the API path tree. Resources with state subdivision (`/orders/drafts`, `/orders/sent`, `/invoices/booked`, etc.) get a *dispatcher* endpoint at the resource root and *leaf sub-endpoints* per state. Every leaf collection sub-endpoint exposes the same three-method shape:

```
getPage(?CollectionRequestOptions): Collection<T>     // one page
getByNumber(int|string): ?T                            // one specific item; null on 404
paginate(?CollectionRequestOptions): \Generator<T>     // walk all pages
```

Single-resource leaves (currently just `SelfEndpoint`) expose only `get(): T` (memoized — see Self decision below).

The class hierarchy:

```
Endpoint                                 (abstract; holds $client + $mapperBuilder)
├── ResourceEndpoint<T of Resource>   (abstract; declares getPath + getItemClass;
│   │                                     provides getOne(int|string|null $id))
│   ├── CollectionEndpoint<T>            (abstract; adds getItem + getPage + paginate)
│   │   ├── ProductsEndpoint             (leaf; @extends CollectionEndpoint<Product>)
│   │   ├── DraftOrdersEndpoint          (leaf; @extends CollectionEndpoint<Order>)
│   │   ├── SentOrdersEndpoint           (leaf; @extends CollectionEndpoint<Order>)
│   │   └── BookedInvoicesEndpoint       (leaf; @extends CollectionEndpoint<BookedInvoice>)
│   └── SelfEndpoint                     (leaf; @extends ResourceEndpoint<Self_>;
│                                         memoizes get())
├── OrdersEndpoint                       (dispatcher; exposes drafts() / sent())
└── InvoicesEndpoint                     (dispatcher; exposes booked())
```

Dispatchers (`OrdersEndpoint`, `InvoicesEndpoint`) stop at `Endpoint` — they don't have a single path or item class to declare. Their sub-endpoint accessors (`drafts()`, `sent()`, `booked()`) are lazy and idempotent, exactly like the top-level `Client` accessors.

**Why three abstract tiers instead of two:** `ResourceEndpoint` formalizes the concept "I'm a single REST resource at a path with a typed DTO." Both collection leaves and `SelfEndpoint` fit that concept and benefit from a shared `getOne()` helper (GET + decode + Valinor map + `$raw` stamp). Without the tier, `SelfEndpoint` would either duplicate that pipeline or pull from `CollectionEndpoint` (which it isn't — `SelfEndpoint` has no pagination or by-id semantics). The third tier keeps the inheritance honest: `getItem(int|string $id)`, `getPage()`, `paginate()` are CollectionEndpoint-only because they only make sense for collections.

**Why dispatchers vs flat method names:** the API growth pattern in e-conomic is heavily state-keyed — orders have draft/sent/archived; invoices have draft/booked/sent/paid/unpaid/overdue/not-due. Flat naming (`getDraftPage`, `getSentPage`, `getBookedPage`, `getPaidPage`, …) bloats the class as states are added. The dispatcher pattern keeps method counts on each class constant; adding a new state is "one new leaf class + one new accessor method," not "N new methods on the existing class."

**File layout:** dispatchers and standalone leaves (`ProductsEndpoint`, `SelfEndpoint`) live alongside the abstract bases at the top of `src/Client/Endpoint/`. State-keyed leaves under a dispatcher live in a subfolder named after the dispatcher: `src/Client/Endpoint/Orders/DraftOrdersEndpoint.php`, etc.

### `ResourceEndpoint<T>` + `CollectionEndpoint<T>` abstract bases

The two abstract tiers carve up the responsibilities cleanly:

```
abstract class ResourceEndpoint<T of Resource> extends Endpoint
{
    abstract protected static function getPath(): string;
    abstract protected static function getItemClass(): class-string<T>;

    /** Fetch one resource, map via Valinor, stamp $raw. @return T */
    protected function getOne(int|string|null $id = null): Resource
    {
        $path = $id === null
            ? static::getPath()
            : sprintf('%s/%s', static::getPath(), $id);

        $data = $this->client->get($path);
        $item = $this->mapperBuilder->mapper()->map(static::getItemClass(), Source::array($data));
        $item->raw = $data;
        return $item;
    }
}

abstract class CollectionEndpoint<T of Resource> extends ResourceEndpoint<T>
{
    /** Try getOne; 404 → null. @return T|null */
    protected function getItem(int|string $id): ?Resource
    {
        try { return $this->getOne($id); } catch (NotFoundException) { return null; }
    }

    /** @return Collection<T> */
    public function getPage(?CollectionRequestOptions $opts = null): Collection { ... }

    /** @return \Generator<T> */
    public function paginate(?CollectionRequestOptions $opts = null): \Generator { ... }
}
```

A leaf sub-endpoint becomes ~25 lines instead of ~70 — just its `getByNumber` (now a one-liner) plus the two abstract hints:

```
final class DraftOrdersEndpoint extends CollectionEndpoint   // @extends CollectionEndpoint<Order>
{
    public function getByNumber(int $number): ?Order { return $this->getItem($number); }

    protected static function getPath(): string { return 'orders/drafts'; }

    /** @return class-string<Order> */
    protected static function getItemClass(): string { return Order::class; }
}
```

And `SelfEndpoint`, which is NOT a `CollectionEndpoint`, gets to share `getOne()` too:

```
final class SelfEndpoint extends ResourceEndpoint     // @extends ResourceEndpoint<Self_>
{
    private ?Self_ $cached = null;

    public function get(): Self_ { return $this->cached ??= $this->getOne(); }

    protected static function getPath(): string { return 'self'; }
    /** @return class-string<Self_> */
    protected static function getItemClass(): string { return Self_::class; }
}
```

**Why ResourceEndpoint exists between Endpoint and CollectionEndpoint:** without it, either (a) `SelfEndpoint` duplicates the fetch+map+stamp pipeline, or (b) `getPath`/`getItemClass`/`getOne` go on `Endpoint` itself — forcing dispatcher endpoints (which have no single path or item class) to declare placeholder values. ResourceEndpoint formalizes "I'm a single REST resource at a path" — both collection leaves and SelfEndpoint fit; dispatchers don't.

**Why centralize this much:** the Valinor `map(class, Source::array($data))` call + `$dto->raw = $data` stamp were identical across every leaf. Pushing them up means a new resource only declares what's genuinely unique — typically just `getPath()`, `getItemClass()`, and a thin public method that delegates to `getItem` or `getOne`. PHPStan generics ride through cleanly via the `@extends CollectionEndpoint<X>` declaration on each leaf.

**Why server-driven walking over `skipPages++`:** the server owns the page-boundary contract. If `pageSize` changes mid-iteration or the API evolves its pagination model, the URL-following walker survives where the counter does not. There's also no off-by-one risk.

**Why `paginate()` lives on the endpoint, not on `Collection<T>`:** putting it on Collection required injecting a fetcher closure into every Collection instance and threading the "what if the closure is null" edge case through the API. Moving the walker to the endpoint, where the `Client` and the mapper are naturally in scope, removes both. `Collection<T>` becomes a passive data carrier again: `collection`, `pagination`, `raw`. Nothing else.

**Consumer-facing usage:**

```
foreach ($client->orders()->sent()->paginate() as $order) { ... }
foreach ($client->products()->paginate() as $product) { ... }
```

**`Client::getUrl()` is still needed** — `CollectionEndpoint::fetchPageByUrl()` uses it to GET the absolute `nextPage` URL without re-prefixing the base URI. Just not on the consumer-facing path.

**Naming of public methods on leaf sub-endpoints (`getPage` / `getByNumber` / `paginate`):**

```
getPage()       ← explicit cardinality; alternative `page()` parses as noun (ambiguous)
getByNumber()   ← prepositional-phrase reading completes with the `get` prefix
paginate()      ← genuinely different verb (walking, not fetching once); stays unprefixed
```

The `get` prefix is dominant in PHP and PSR (`getX()` accessors). The asymmetry with `paginate()` is intentional: it's the one method that's a fundamentally different action. Alternative naming (`page()` / `byNumber()`, or `fetchPage()` / `fetchByNumber()`) was considered and rejected — see Alternatives below.

**Alternatives considered for the public method names:**
- `page()` / `byNumber()` / `paginate()` — shorter; rejected because `page()` and `byNumber()` are noun/preposition-style and parse less clearly than `getPage()` / `getByNumber()`.
- `fetchPage()` / `fetchByNumber()` / `paginate()` — all-verbs, internally consistent; rejected because `fetch` is just a synonym for `get` without any clarification gain, and PHP convention favors `get`.

### `$raw` is on entry-point DTOs only

Entry-point DTOs (the things an endpoint method returns: `Product`, `Order`, `BookedInvoice`, and `Collection<T>`) get `public array $raw = []`, populated by the endpoint after Valinor finishes mapping. Nested DTOs (`Inventory`, `Line`, `Pagination`, `Page`) do NOT — they are reachable via the parent's `$raw['nested-key']`.

**Why on every entry-point Resource (including items inside a Collection):** the goal of `$raw` is "let consumers reach untyped fields without forcing them to PR new typed fields into the SDK." Pagination breaks that contract if items inside `Collection<T>::$collection` don't carry `$raw` — consumers iterating `paginate()` would have to either type-discriminate to reach typed fields, or pull values from the parent's `$raw['collection'][$i]` (re-doing the index work the SDK already did). So `CollectionEndpoint::mapPage()` stamps each item's `$raw` after Valinor maps the page envelope. The rule simplifies: every `Resource` subclass has `$raw` populated whenever the SDK constructs it.

**Why not on truly-nested non-Resource DTOs:** `Inventory`, `Line`, `Pagination`, `Page` don't extend `Resource` — they're embedded value objects inside their parent. Putting `$raw` on them requires threading the raw array down through Valinor (custom source modifiers, per-DTO constructors, or post-mapping reflection walks) for marginal benefit. Consumers wanting an undeclared field on a truly-nested object can reach it via `$product->raw['inventory']['someField']`.

**How `$raw` gets populated:** the endpoint already has the decoded JSON in hand at the point it calls `Valinor::map()`. The pattern is `$dto = $mapper->map(...); $dto->raw = $decoded; return $dto;`. This requires `$raw` to be a non-readonly property — so entry-point DTOs cannot be `final readonly class`. Individual typed fields stay `public readonly`, but the class itself can't carry the class-level `readonly` modifier.

**Rector interaction:** `ReadOnlyClassRector` will try to re-apply `readonly class` to these DTOs. Add the affected classes (or the `Response/` namespace) to `rector.php`'s `skip` list.

**A shared base class `Resource` carries `$raw`** so the concrete DTOs only declare their typed fields. The base is abstract.

**Alternatives considered:**
- `$extra` (residue only, fields Valinor didn't claim) — rejected. The user explicitly chose `$raw` (full JSON) for simplicity.
- `Typed<T>` wrapper envelope (`$response->data`, `$response->raw`) — rejected. Every call site grows a `->data` and the API gets noisier.
- Endpoint-level "last raw body" accessor — rejected. Race-y if endpoints are shared.
- `$raw` on every DTO including nested — rejected. Implementation cost not worth the marginal benefit.

### Exception hierarchy: marker interface + 4xx/5xx bases + status-specific concrete types

```
EconomicException                       (interface; marker)
└── ResponseAwareException              (abstract; owns response + lazy-parsed error)
    ├── ClientErrorException            (abstract; 4xx)
    │   ├── UnauthorizedException       (401)
    │   ├── ForbiddenException          (403)
    │   ├── NotFoundException           (404)
    │   ├── MethodNotAllowedException   (405)
    │   └── ValidationException         (400 + 422)
    └── ServerErrorException            (abstract; 5xx)
        ├── InternalServerErrorException(500)
        └── NotImplementedException     (501)
```

Plus a catch-all `UnexpectedStatusCodeException` for non-2xx codes that don't match any of the above (kept for safety).

**Lazy parsing:** every `ResponseAwareException` holds the PSR response. Getters `getErrorCode()`, `getDeveloperHint()`, `getLogId()`, `getLogTime()`, `getValidationErrors()` decode the JSON body the first time they're called and memoize the result. If the body isn't valid JSON, getters return `null` (or `[]` for the list) — never throw from an exception class.

**Validation error shape:** `getValidationErrors(): array<string, mixed>` returns e-conomic's raw nested document verbatim. The wire format is recursive — fields are replaced by `{errors: [...]}` objects; arrays carry per-index error blocks with `arrayIndex` markers. We deliberately do NOT type this into a `ValidationError` tree in v2. Rationale: the structure mirrors the request payload, which itself isn't typed in v2 (we have no write methods). Typing the error tree before the request tree is typed would be premature.

**`getLogTime()`** parses the `logTime` field (an ISO-8601 timestamp on validation errors) into `?\DateTimeImmutable`. Returns `null` if absent or malformed.

**Why a marker interface at the top:** lets consumers write `catch (EconomicException $e)` to net everything from this SDK without catching `\Throwable` or maintaining a list. Standard pattern (Stripe, AWS SDK).

**No `RateLimitException`.** e-conomic's documented HTTP status codes don't include 429, so there's no production code path that would throw a rate-limit exception. If the API ever does return 429 for some endpoint, it surfaces as `UnexpectedStatusCodeException` and consumers can read `$e->getResponse()->getHeaderLine('Retry-After')` directly. The SDK still explicitly does not implement retry functionality regardless.

**Why 400 and 422 both map to `ValidationException`:** e-conomic returns 400 for malformed input and 422 for validation failures; both carry structured `errors[]`. Distinguishing them at the type level adds little value for catch sites.

**Why 405 and 501 get named subclasses:** 405 ("wrong HTTP verb for this resource") signals a real consumer-side mistake worth catching specifically. 501 is genuinely useful — e-conomic uses it for "endpoint linked but not yet implemented," so a consumer following self-links could encounter it on a sub-resource that's documented but not live. 415 ("unsupported media type") is *not* given a named subclass because it indicates an SDK bug (we sent the wrong `Content-Type`), not a consumer-recoverable condition — it falls through to `UnexpectedStatusCodeException`.

**Mapping mechanism:** `Client::assertStatusCode()` becomes a switch on status code that throws the right concrete type. The existing `NotFoundException::assert()` / `InternalServerErrorException::assert()` static factories go away — centralizing the mapping in `Client` is clearer than scattering it across exception classes.

### Drop `Query`, drop endpoint interfaces, drop the logger

These are three independent simplifications grouped because they share the same justification: each is an abstraction whose ongoing cost (extra class to maintain, extra interface to keep in sync, extra wiring to thread) exceeds the value it delivers in the current code.

**`Query`:** wraps an array with `isEmpty()` and `__toString()`. Both inlinable in `Client::get()` in a single line each. No code in the SDK or README builds a `Query` ahead of time and passes it around. `CollectionRequestOptions::asQuery()` becomes `toArray(): array<string, scalar|null>`.

**Endpoint interfaces:** `ProductsEndpointInterface`, `OrdersEndpointInterface`, `InvoicesEndpointInterface`, and the base `EndpointInterface` all go. The concrete classes are `final`; consumers test against them via `Client::setHttpClient()` with a fake PSR-18 client. `ClientInterface` is kept (per user request) because downstream tests want to mock the client itself.

**Logger:** only used in one place (`Endpoint::createSourceFromResponse`) to log-and-rethrow a JSON parse failure. The exception already carries `getLastRequest()` / `getLastResponse()` via `Client`. The log message's context (request method, URI, body excerpt) moves into the exception's message string. `psr/log` is removed from `require`. `LoggerAwareInterface` is removed from `Client` and `Endpoint`.

**Alternative considered for endpoint interfaces:** keep them but mark `@internal`. Rejected — they add coupling without delivering swap-ability that anyone exercises.

### `User-Agent` header

Stamped in `Client::request()` alongside the auth headers. Value resolved at runtime:

```
Setono-Economic-PHP/<version> (+https://github.com/Setono/economic-php-sdk)
```

`<version>` comes from `\Composer\InstalledVersions::getVersion('setono/economic-php-sdk')`. If that returns `null` (unusual autoload setups), fall back to `dev`. No manual version constant — Composer keeps it accurate.

### `Client::get()` accepts both relative paths and absolute URLs

`Client::get(string $uri, array $query = []): array` handles both forms:

- If `$uri` starts with `http://` or `https://`, treat as absolute; the URL is used verbatim and `$query` MUST be empty.
- Otherwise, treat as a relative path against the SDK's base URI; `$query` is appended via `http_build_query(..., PHP_QUERY_RFC3986)`.

**Why one method:** the only legitimate source of absolute URLs in this SDK is server-provided pagination links — which always point back to e-conomic's own host. Having a single `get()` removes an extra public method, simplifies the pagination walker (`$this->client->get($nextUrl)`), and keeps the consumer-facing API smaller. Earlier two-method designs split this for "explicit intent," but the security trade-off (below) is solvable inline.

**Host-mismatch guard:** when `$uri` is absolute, the host MUST match the SDK's base host. If not, `Client::get()` throws `\InvalidArgumentException` BEFORE dispatching — the SDK refuses to send `X-AppSecretToken` / `X-AgreementGrantToken` to a host it wasn't configured for. This catches the realistic failure mode where consumer code accidentally feeds user-supplied input into `get()`.

**Absolute + `$query` is rejected:** absolute URLs already encode their own query string. Combining with a non-empty `$query` is ambiguous (merge? override? append?), so `Client::get()` throws `\InvalidArgumentException` rather than guessing.

**Detection regex:** `#^https?://#i`. Protocol-relative `//host/...` URIs are NOT treated as absolute — they'd fail the host guard if they did, but treating them as relative paths is more useful (and e-conomic never produces them).

### `Client::self()` returns a `SelfEndpoint`, consistent with other endpoint accessors

`Client::self(): SelfEndpoint` returns a lazily-constructed endpoint object — same pattern as `products()`, `orders()`, `invoices()`. `SelfEndpoint::get(): Self_` performs `GET /self`, maps the response, memoizes the DTO inside the endpoint, and returns it. Subsequent calls to `get()` on the same `SelfEndpoint` instance return the cached DTO without another HTTP request.

**Why the endpoint shape, even though `Self` is singular:** the e-conomic API has `PUT /self/user`, `PUT /self/company`, and `PUT /self/company/bankinformation` — `Self` is a write target as well as a read target. When write operations land in v3, those become `SelfEndpoint::putUser(...)`, `SelfEndpoint::putCompany(...)`, etc. Returning the DTO directly from `Client::self()` would leave no place to hang the write methods. The endpoint shape keeps the v3 design clean.

**Class naming:** `Self` is a PHP reserved word — `class Self` won't compile. The DTO class is named `Self_` (trailing underscore is the standard PHP convention for reserved-word collisions). The endpoint class is `SelfEndpoint`. Alternatives considered:
- `Agreement` — descriptive of what the resource actually is (every Self response is scoped to one agreement) — rejected because the e-conomic docs and URL both say "self," which keeps the SDK vocabulary aligned with the API.
- `Me` — terse but adds a name the e-conomic docs never use — rejected.
- `SelfInfo` — verbose without adding clarity — rejected.

**`Self_` is an entry-point DTO:** extends `Resource`, carries `$raw`. Typed fields populated by Valinor. Memoization lives in `SelfEndpoint`, not in `Client`.

### `Client::get` returns decoded JSON, not `ResponseInterface`

`Client::get(string $uri, array $query = []): array<string, mixed>` and `Client::getUrl(string $absoluteUrl): array<string, mixed>` decode the JSON body before returning — they do NOT return `Psr\Http\Message\ResponseInterface`. The decode logic lives privately on `Client` (`decodeJson()`); `Endpoint` no longer carries a decode helper.

The endpoint's mapping pipeline becomes:

```
$data = $this->client->get('products', $opts->toArray());  // array<string, mixed>
$dto  = $mapper->map(Product::class, Source::array($data));
$dto->raw = $data;                                          // entry-point DTO only
return $dto;
```

**Why decode lives on `Client`:**
- The SDK already commits to JSON (forces `Content-Type: application/json` on every request); making the return type honest about that improves the public contract.
- Every internal caller of `get` decoded immediately afterward — the boilerplate is now centralized.
- Consumers using `get` as an escape hatch for un-wrapped endpoints get usable JSON instead of having to hand-decode `$response->getBody()`.

**Trade-off: non-JSON endpoints.** e-conomic has binary endpoints (PDFs, attachment files). Those can't use `Client::get` — consumers (and any future wrapping) MUST use `Client::request()` with a hand-built PSR-7 request, which still returns `ResponseInterface`. When we wrap PDF/attachment endpoints in v3, we'll add a dedicated `Client::getBinary()` (or similar). Speculating now is premature.

**JSON errors throw `\RuntimeException`** with the request method, URI, and a body excerpt baked into the message — same context the dropped logger would have emitted. Failures going through `assertStatusCode` (non-2xx responses) take the typed-exception path and never reach the decoder.

## Risks / Trade-offs

- [The `$raw` field doubles memory for typed fields] → Acceptable. e-conomic responses are small (KB range, not MB). If a large list response duplicates, the cost is bounded by the page size. Documented in the README's "production usage" section.
- [Entry-point DTOs cannot be `final readonly class` because `$raw` is mutable] → Rector's `ReadOnlyClassRector` will try to re-apply class-level readonly. Mitigated by adding the affected classes to `rector.php`'s skip list. Per-property `readonly` on the typed fields preserves their immutability.
- [Lazy JSON parsing in exception getters could throw on malformed bodies] → Exception getters catch and return `null` / `[]`. Exceptions never re-throw from inside getters.
- [Removing endpoint interfaces breaks consumers who type-hint against them] → Acceptable in a major release. README migration section will name them explicitly.
- [`Composer\InstalledVersions::getVersion()` returns null in some autoload setups] → Fall back to `dev`. The User-Agent is for telemetry/identification, not correctness; `dev` is acceptable.
- [`paginate()` makes an unbounded number of HTTP requests] → Documented in PHPDoc. Consumers who need bounded iteration can use `getPage()` directly with explicit `skipPages` (still supported).
- [The dispatcher / leaf-sub-endpoint structure forces consumers to know an extra level (`->drafts()->`) for orders and invoices] → Acceptable. The API growth pattern (heavily state-keyed) makes the alternative — flat method-name explosion (`getDraftPage`, `getSentPage`, `getBookedPage`, `getPaidPage`, …) — worse as v3 adds more states. README migration section names the new shape explicitly.
- [URL concatenation in direct-lookup methods (`sprintf('products/%s', $number)`) is inconsistent with the docs' "never concatenate any urls" guidance] → Deliberate pragmatic trade-off. Pure HATEOAS for "get product #5" would require an extra `GET /` round-trip to fetch URI templates, then template substitution — still constructing a URL, just via a different mechanism. We follow links where the data provides them (`pagination.nextPage.url` via `Collection::paginate()`) and concatenate for trivial by-ID lookups. Documented in CLAUDE.md so contributors don't try to "fix" it.
- [204 No Content responses break the JSON-decoding pipeline] → Not relevant for v2. Every 204 response in the e-conomic API is a `DELETE` response (verified by reading the docs); v2 has no DELETE methods. Will need handling when write operations land in v3.
- [Idempotency tokens (`Idempotency-Key` header) are supported server-side but not surfaced by the SDK] → Not relevant for v2. The feature is explicitly unavailable on GET requests, and v2 has only GETs. Will be designed-in when write operations land in v3.

## Migration Plan

Internal-only release (no production consumers using v2 yet during development). The migration story lives in the **README v2 migration section** which is part of the implementation:

1. `composer require setono/economic-php-sdk:^2.0`
2. **Endpoint shape changes:**
   - `$client->products()->get(...)` → `$client->products()->getPage(...)`
   - `$client->products()->get(skipPages: $i++)` loop → `foreach ($client->products()->paginate() as $p) { ... }`
   - `$client->orders()->getDraft(...)` → `$client->orders()->drafts()->getPage(...)`
   - `$client->orders()->getDraftByNumber(5)` → `$client->orders()->drafts()->getByNumber(5)`
   - `$client->orders()->getSent(...)` → `$client->orders()->sent()->getPage(...)`
   - `$client->orders()->getSentByNumber(5)` → `$client->orders()->sent()->getByNumber(5)`
   - `$client->invoices()->getBooked(...)` → `$client->invoices()->booked()->getPage(...)`
   - `$client->invoices()->getBookedByNumber(5)` → `$client->invoices()->booked()->getByNumber(5)`
   - Walk-all: `foreach ($client->orders()->sent()->paginate() as $order) { ... }`
3. Replace `catch (NotFoundException)` calls — still works; if catching more specifically, use the new 4xx subtypes.
4. Replace `CollectionRequestOptions::asQuery()` with `::toArray()`.
5. Remove `new Query(...)` constructions; pass arrays directly.
6. Remove any `implements ProductsEndpointInterface` etc.; type against the concrete classes.
7. If consuming `Client::setLogger()` — it's gone. Wrap the PSR-18 client to log.

No deprecation cycle. Released as `2.0.0`.

## Open Questions

- Should the JSON-decoded `$raw` on `Collection<T>` be the entire response envelope, or just the `collection`/`pagination` portion? Recommend: the entire envelope, since that's the freshest "this is exactly what came back" view.
- Does `EndpointInterface` (the base) have any methods worth preserving? Need to read the file during tasks before deleting it.
