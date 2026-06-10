## Context

This is the SDK's first write endpoint. The exploration phase converged on:
- a fully typed request-DTO surface (vs. raw arrays or PHPStan shape aliases),
- a single generic `Identifier` value object with named factories (vs. ~11 per-reference classes),
- Valinor's normalizer for object → JSON serialization (vs. `\JsonSerializable`),
- a strict `Client::post(string, object)` helper (vs. accepting `object|array`),
- server-side validation only (no client-side schema mirroring).

The Valinor 2.x docs revealed a non-obvious shape: the normalizer is built from `CuyZ\Valinor\NormalizerBuilder`, a **separate** type from `MapperBuilder`. The two builders are not interchangeable; the normalizer cannot be obtained from a `MapperBuilder` instance. This forces the BYOHC surface to grow another collaborator slot.

The pre-wired `?StreamFactoryInterface` from the BYOHC change (the unused-until-now parameter on `Client::__construct`) is finally consumed here: `Client::post()` uses the stream factory to build the request body.

## Goals / Non-Goals

**Goals:**
- A clean, typed, fully-discoverable consumer surface for creating draft orders: `$client->orders()->drafts()->create(new DraftOrderRequest(...))`.
- Cover every field the schema permits, including optional ones, without forcing a `$extra: array` escape hatch.
- One generic `Identifier` class with 11 named factories — set the pattern for every future write endpoint that needs foreign-key references.
- Symmetric extension of the BYOHC surface: consumers can supply their own `NormalizerBuilder` (typically with a cache) the same way they already supply a `MapperBuilder`.
- A reusable `Client::post()` helper so future write endpoints (`POST /products`, etc.) don't each reinvent body-stream construction.
- Server-side validation is the source of truth. Existing `ValidationException` already exposes e-conomic's validation document via `getValidationErrors()`.

**Non-Goals:**
- A shared `WritableEndpoint` abstract tier. With exactly one writable endpoint today, pre-abstracting would invent structure. The refactor moment is "when the second write endpoint lands."
- `Client::put()` / `Client::delete()` helpers. Add them when the first PUT/DELETE endpoint arrives.
- Client-side schema validation (required fields beyond the constructor's type system, format checks, enum membership, conditional required-ness like "`dueDate` if `paymentTermsType` is `dueDate`"). The server enforces.
- Round-tripping the POST response into a fuller `Order` DTO. The existing `Order` types only `orderNumber` and `lines`; the rest of the POST response lands in `$raw`. Expanding `Order` is a separate change.
- Per-reference type discrimination in the type system. Passing `Identifier::customer(1)` where `layout:` is expected compiles; the server returns 400. Accepted as the cost of the 11-classes → 1-class trade.
- Forward-deprecation shim for the previous 6-arg `Client::__construct`. 1.x is unreleased; a 7th optional named arg is purely additive at the call site.

## Decisions

### Decision: Single `Identifier` class with named factories + Valinor transformer

```php
final readonly class Identifier
{
    /** @param non-empty-string $fieldName */
    private function __construct(
        public string $fieldName,
        public int    $value,
    ) {}

    public static function layout(int $n):           self { return new self('layoutNumber', $n); }
    public static function paymentTerms(int $n):     self { return new self('paymentTermsNumber', $n); }
    public static function customer(int $n):         self { return new self('customerNumber', $n); }
    public static function vatZone(int $n):          self { return new self('vatZoneNumber', $n); }
    public static function project(int $n):          self { return new self('projectNumber', $n); }
    public static function deliveryLocation(int $n): self { return new self('deliveryLocationNumber', $n); }
    public static function product(int $n):          self { return new self('productNumber', $n); }
    public static function unit(int $n):             self { return new self('unitNumber', $n); }
    public static function employee(int $n):         self { return new self('employeeNumber', $n); }
    public static function customerContact(int $n):  self { return new self('customerContactNumber', $n); }
    public static function vendor(int $n):           self { return new self('vendorNumber', $n); }
}
```

A Valinor transformer registered on the SDK's default `NormalizerBuilder` outputs `[$id->fieldName => $id->value]` for any `Identifier`:

```php
->registerTransformer(
    static fn (Identifier $id): array => [$id->fieldName => $id->value],
)
```

**Why this over per-reference classes:** one class to maintain, one transformer, 11 factory methods is mechanical, IDE auto-complete still surfaces every reference type. The cost is one bit of compile-time safety: `layout: Identifier::customer(1)` compiles where `layout: LayoutReference(1)` wouldn't. Server returns 400; acceptable.

**Alternatives considered:**
- *Per-reference classes (`LayoutReference`, `CustomerReference`, …).* 11 classes, each 3-5 lines. Type-discriminates the schema constraints at compile time. Rejected as too much boilerplate relative to the safety gained.
- *Enum + single class (`new Identifier(IdentifierKind::Layout, 17)`).* Slightly worse ergonomics (`Identifier::layout(17)` reads better) and the enum is itself maintenance. Rejected.
- *Reading from PHP property hooks (PHP 8.4).* No advantage over the static factory pattern; rejected for cleanliness.

### Decision: Valinor `NormalizerBuilder` as a new 7th constructor parameter on `Client`

```php
public function __construct(
    private readonly string $appSecretToken,
    private readonly string $agreementGrantToken,
    ?HttpClientInterface     $httpClient        = null,
    ?RequestFactoryInterface $requestFactory    = null,
    ?StreamFactoryInterface  $streamFactory     = null,
    ?MapperBuilder           $mapperBuilder     = null,
    ?NormalizerBuilder       $normalizerBuilder = null,   // ← new
) {
    // ...
    $this->normalizerBuilder = $normalizerBuilder ?? self::defaultNormalizerBuilder();
}
```

`Client::defaultNormalizerBuilder()` builds a `NormalizerBuilder` with:
1. The `Identifier` transformer registered (`fn (Identifier $id): array => [$id->fieldName => $id->value]`). Terminal — no `$next` parameter.
2. A null-skipping transformer keyed on a marker interface: `fn (Payload $obj, callable $next): array => array_filter($next(), static fn ($v) => $v !== null)`. Every request DTO (`DraftOrderRequest`, `Recipient`, `Delivery`, `Notes`, `References`, `Line`) implements the empty marker interface `Setono\Economic\Request\Payload`. The transformer fires on each DTO, calls `$next()` to invoke Valinor's default object-normalization (which produces an array with one key per public property — including `null` values for unset optional fields), then strips the nulls. **Why marker interface vs. one transformer per DTO class:** Valinor matches the transformer's first-param type against the input value, not against the synthesized output. A transformer typed `(array $a, callable $next)` would only fire on arrays present *in* the source object (like `?list<Line> $lines`), not on the object-normalized output. Per-DTO transformers would be six identical closures; one marker interface is DRY and self-documenting.

A new `public function getNormalizerBuilder(): NormalizerBuilder` getter exposes the resolved builder so internal endpoint code can build a normalizer on demand. Mirrors `getStreamFactory()`. **Not** added to `ClientInterface`.

**Why a separate ctor parameter:** Valinor 2.x explicitly separates `MapperBuilder` (deserialize) from `NormalizerBuilder` (serialize). They cannot be unified. Consumers who care about caching mapper output will care equally about caching normalizer output, so the BYOHC surface needs both slots.

**Alternatives considered:**
- *Build the normalizer internally each `post()` call from a hard-coded config.* Loses the BYOHC pluggability; consumers can't supply a cache. Rejected.
- *Add `NormalizerBuilder` to the existing `MapperBuilder` parameter as a pair object.* Couples two unrelated Valinor types into a synthetic SDK wrapper. Rejected.

### Decision: `Client::post(string $uri, object $body): array<string, mixed>`

```php
public function post(string $uri, object $body): array
{
    $url     = $this->resolveUrl($uri, []);
    $json    = $this->normalizerBuilder->normalizer(Format::json())->normalize($body);
    $stream  = $this->streamFactory->createStream($json);
    $request = $this->requestFactory->createRequest('POST', $url)->withBody($stream);

    $response = $this->request($request);

    return self::decodeJson($request, $response);
}
```

**Why strict `object`:** the entire point of choosing typed request DTOs was type safety. Accepting `array` as a fallback would erode that immediately. Consumers wanting array-style POST go through `Client::request()` (already the documented escape hatch for non-JSON / raw needs).

**Why no separate `$query`:** POSTs to e-conomic don't take query parameters in the v2-redesign read surface; the few endpoints that need them can extend this signature later or build the URL upstream. YAGNI.

### Decision: Request DTOs use `?` defaults for optional fields; null-skipping transformer omits them at serialization

All `DraftOrderRequest` properties beyond the 6 required ones default to `null`. The normalizer's null-skipping transformer ensures the JSON body contains only the keys the consumer explicitly populated.

**Why this over an explicit "set" tracking mechanism:** simpler. PHP 8.4 readonly + nullable defaults is the cleanest mental model. The cost is that consumers cannot send literal `null` to clear a server-side value — but POST doesn't have that semantic anyway (it's a create, not an update). PUT, when it arrives, may need a different shape.

### Decision: `Webmozart\Assert` checks at DTO construction — limited to `notEmpty` and `positive`

The DTO constructors assert:
- `Assert::notEmpty($date)` on required string fields (`$date`, `$currency`, `$recipient->name`)
- `Assert::positive($n)` on every `Identifier::*` factory's `$n` parameter
- `Assert::range($currency, 3, 3)` if length-checking ISO 4217 currency codes is cheap (open)

**Not asserted:** date format, currency-code enum membership, conditional `dueDate` required-ness, line-item totals. All server-validated. This matches the read-side stance — `Client::get()` doesn't validate query params either.

### Decision: `create()` lives directly on `DraftOrdersEndpoint`, not in a new abstract tier

Adding `WritableEndpoint<T>` with a generic `create(T $req): T` abstract method is premature. With exactly one writable endpoint, the abstraction has no second user to validate the shape. Inline `create()` is one method, one line of dispatch (`return $this->mapResponse($this->client->post(static::getPath(), $req))`). When the second write endpoint lands (likely `POST /products`), refactor.

### Decision: Response is mapped into `Order` via the existing Valinor pipeline

The POST response is structurally similar to `GET /orders/drafts/:n` (the schema doesn't explicitly document the POST response body, but e-conomic conventionally returns the created resource). `create()` maps the response into the existing `Order` DTO via the existing `MapperBuilder`. Untyped fields land in `$raw` (parity with `getByNumber()`).

If real responses turn out to be structurally different (different envelope, different field set), a follow-up change can introduce `DraftOrderCreated` (or similar). Starting optimistic.

## Risks / Trade-offs

- **Type safety regression on `Identifier`.** `layout: Identifier::customer(1)` is a 400 from the server, not a compile error. *Mitigation:* the named factories make the mistake unlikely in practice; server errors are surfaced as `ValidationException` with the offending field name in `getValidationErrors()`.
- **Valinor normalizer cache cost.** Without a `NormalizerBuilder` cache, every `post()` pays the normalizer compile cost. *Mitigation:* the README will document that consumers should pass a cached `NormalizerBuilder` for production, mirroring the existing guidance for `MapperBuilder`.
- **Null-skipping is global (all arrays).** The chained array-level transformer filters nulls everywhere — including, hypothetically, places where the consumer or schema might want an explicit `null`. *Mitigation:* the schema doesn't require any explicit nulls in POST bodies. If a future endpoint needs to send `"field": null`, we revisit then.
- **`ClientInterface::post()` addition is a BC break for implementers.** Any code typed against `ClientInterface` with its own mock implementation needs to add `post()`. *Mitigation:* the project has no in-tree implementers besides `Client`; downstream consumers using prophecy mocks against the interface will get a clear method-not-implemented error. 1.x is unreleased.
- **Schema drift.** The schema may evolve server-side, gaining new optional fields the SDK doesn't type yet. *Mitigation:* `DraftOrderRequest::$extra: array<string, mixed>` would address this but reintroduces the option-D ergonomics we rejected. Leave it for now; add when first encountered.
- **Two transformer chains in one `NormalizerBuilder`.** Identifier-rewriting (fires for `Identifier` instances) and null-skipping (fires for arrays) must compose cleanly. *Mitigation:* both are registered closures; the null-skipper runs AFTER the Identifier-rewriter (because the rewriter returns an array that the array-level transformer then sees). Need a small integration test to lock this.

## Migration Plan

Pure additive at the package level — single-shot, no phased rollout because 1.x is unreleased.

1. Add the 7th `?NormalizerBuilder $normalizerBuilder` parameter to `Client::__construct`, plus `defaultNormalizerBuilder()`, plus the `getNormalizerBuilder()` getter.
2. Add `Client::post()` and the matching `ClientInterface::post()` declaration.
3. Create `src/Request/Identifier.php` and `src/Request/Order/*` DTOs.
4. Add `DraftOrdersEndpoint::create()`.
5. Tests: round-trip with a `ScriptedHttpClient` fake asserting exact request URL, method, headers (`Content-Type: application/json`, auth, UA), and body (the JSON-encoded normalized payload). Cover the null-skipping path explicitly.
6. README + CLAUDE.md updates.
7. Full verify (`composer phpunit`, `composer analyse`, `composer check-style`, `vendor/bin/rector --dry-run`); Infection skipped locally as before.

## Open Questions

- **`References` (the order's metadata block) — DTO or array?** The schema's `references` field contains a small dict (`salesPerson`, `customerContact`, `vendorReference`, `other`). Three of those are `Identifier`s; one is a free-text string. A typed `References` DTO is consistent with the rest of the request surface. Locked as a typed DTO.
- **Valinor transformer for `Identifier`: registered closure or `#[AsTransformer]` attribute?** Closure is simpler and keeps the Identifier class free of Valinor coupling. The attribute approach requires defining `Identifier` (or a per-class wrapper attribute) with knowledge of Valinor namespaces. Decision: **closure**, registered inside `Client::defaultNormalizerBuilder()`. Consumers supplying their own `NormalizerBuilder` MUST register the transformer themselves; this is documented and exposed via a static helper `Identifier::registerTransformer(NormalizerBuilder): NormalizerBuilder` for convenience.
- **Currency code validation.** Webmozart `Assert::length($currency, 3)` is a 1-line guard against obvious typos. Worth adding? Lean yes — cheap, catches real bugs (someone passing `'EUR '` with trailing space), and doesn't replicate the server's ISO 4217 enum check (which would require a maintained list).
