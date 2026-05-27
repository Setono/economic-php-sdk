## Context

The v2 SDK redesign (change `v2-sdk-redesign`, complete but unarchived) shipped a `Client` whose two HTTP collaborators — a PSR-18 client and a PSR-17 request factory — are configured through post-construction setters (`setHttpClient`, `setRequestFactory`), with `php-http/discovery` as the lazy fallback inside `getHttpClient()` / `getRequestFactory()`. The Valinor `MapperBuilder` is plumbed the same way (`setMapperBuilder`). This shape predates the broader PHP ecosystem's convergence on the "bring your own HTTP client" (BYOHC) pattern documented by Sensiolabs in 2025: collaborators are constructor-injected, defaults come from discovery, and the resulting client is treated as immutable.

The README's "Performance" snippet at line 209 already documents the intended ergonomic — `new Client('A', 'B', (new MapperBuilder())->withCache(...))` — but the actual constructor doesn't take a third argument. So the doc and the code disagree, and we have a moment (pre-1.x release) where breaking the public surface is cheap.

This change converges the two shapes onto the BYOHC pattern.

## Goals / Non-Goals

**Goals:**
- Move HTTP collaborators (`HttpClientInterface`, `RequestFactoryInterface`) plus `MapperBuilder` from setter wiring to constructor-injection with `null`-defaults that resolve via discovery.
- Pre-wire a `StreamFactoryInterface` constructor parameter so the BYOHC surface is complete before write endpoints (POST/PUT) land.
- Make the `Client` immutable with respect to its collaborators after construction — no setters, no swaps mid-life.
- Keep the `Client::getLastRequest()` / `Client::getLastResponse()` journaling surface untouched (these legitimately mutate; that's not a BYOHC concern).
- Make the README's existing constructor-style snippet actually compile.

**Non-Goals:**
- Introducing a `ClientBuilder` or fluent factory. Named constructor arguments with discovery defaults are already ergonomic; a builder would add indirection without payoff.
- Making `Client` `final readonly`. The class still has legitimate mutable journaling state (`$lastRequest`, `$lastResponse`) and lazy endpoint memoization. The Sensiolabs example happens to be stateless; ours isn't, and chasing `readonly` for its own sake would force gymnastics.
- Modifying `ClientInterface`. It already does not expose the setters, so the consumer-facing seam doesn't move.
- Adding write endpoints in this change. The stream factory parameter is pre-wired but unused; its consumers arrive in a later change.
- Changing endpoint construction. Each `Endpoint` continues to be constructed with `(ClientInterface $client, MapperBuilder $mapperBuilder)`. The only diff is that `Client` now passes a constructor-supplied (or default) `MapperBuilder` instead of one produced by a lazy `getMapperBuilder()` accessor.

## Decisions

### Decision: Constructor injection with `null`-default + discovery fallback

```php
public function __construct(
    string $appSecretToken,
    string $agreementGrantToken,
    ?HttpClientInterface     $httpClient     = null,
    ?RequestFactoryInterface $requestFactory = null,
    ?StreamFactoryInterface  $streamFactory  = null,
    ?MapperBuilder           $mapperBuilder  = null,
) {
    $this->httpClient     = $httpClient     ?? Psr18ClientDiscovery::find();
    $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
    $this->streamFactory  = $streamFactory  ?? Psr17FactoryDiscovery::findStreamFactory();
    $this->mapperBuilder  = $mapperBuilder  ?? self::defaultMapperBuilder();
}
```

**Why this over setters:** Constructor injection makes the dependency graph explicit at construction time, supports named-argument call sites in PHP 8.1+, removes a mutable seam, and lets the class fully resolve its collaborators in one place instead of through `??=` lazy getters scattered across methods.

**Alternatives considered:**
- *Keep setters, add ctor args too.* Doubles the surface and leaves the mutable seam in place. Rejected.
- *Builder pattern (`Client::create()->withHttpClient(...)`).* Adds a second public class without solving a real problem. Six ctor args with sensible defaults + named arguments is already pretty clean. Rejected.
- *Config-object (single `ClientConfig` value object).* Adds a class for the sake of avoiding multiple ctor params. PSR ecosystem prefers explicit ctor args. Rejected.

### Decision: Eager discovery via `Psr18ClientDiscovery::find()`, not the lazy `Psr18Client` facade

Today `getHttpClient()` does `new Http\Discovery\Psr18Client()` — a wrapper that defers actual discovery until `sendRequest()` is called for the first time. The BYOHC pattern (and the Sensiolabs article) uses `Psr18ClientDiscovery::find()`, which resolves immediately and returns the discovered client directly.

**Why eager:** Transparency — the resolved client is what's stored, not a wrapper. Easier to reason about in tests and debuggers. The performance cost (discovery on construct vs. on first request) is negligible because real users always end up making at least one request.

**Trade-off:** A consumer who constructs a `Client` and never sends a request pays the discovery cost up front. Acceptable — that's a vanishingly rare case (most often a test that doesn't even hit the http path), and discovery is microseconds.

### Decision: Add `StreamFactoryInterface` now even though no code uses it

The current `Client` only issues GETs, so no body streams are built internally. Write endpoints (POST/PUT for creating products, orders, etc.) are an obvious future need, and when they arrive they'll need a stream factory.

**Why add it now:** Adding a constructor parameter later is a second BC break. Doing it inside this same break is free. Consumers who omit it pay nothing — discovery resolves the default.

**Trade-off:** Mild YAGNI. The parameter is stored on the class but read by nobody until write endpoints land. Acceptable.

**Implementation note (added during apply):** PHPStan at level: max flags any private property that is "written but never read." A genuine read path is required. We add `public function getStreamFactory(): StreamFactoryInterface` so the property is consumed and the BYOHC contract becomes self-evident from the public surface — consumers can verify their custom factory was retained without reflection, and future write-endpoints (which already hold a typed reference to the `Client` via `Endpoint::$client`) can fetch it directly. This getter is **not** added to `ClientInterface`; the interface stays narrow. The Sensiolabs article's example exposes the factory the same way, so this matches ecosystem convention.

### Decision: Remove `setMapperBuilder()` and the lazy `getMapperBuilder()`

The `MapperBuilder` follows the same lifecycle as the HTTP client — set once at construction, consulted by endpoint accessors. The current `getMapperBuilder()` lazy path exists only because the setter path needed a memoizing slot. With constructor injection, the default builder is resolved exactly once in `__construct` and stored.

**Side effect:** Endpoints are still passed the `MapperBuilder` via their own constructor (`new ProductsEndpoint($this, $this->mapperBuilder)`), so they remain unchanged.

### Decision: `Client` stays `final class`, not `final readonly class`

`$lastRequest`, `$lastResponse`, and the lazy `$invoicesEndpoint`/`$ordersEndpoint`/`$productsEndpoint`/`$selfEndpoint` slots are all post-construction mutable state. Marking the class `readonly` would force gymnastics (immutable journaling wrappers, eager endpoint construction in `__construct`) for zero gain. The BYOHC pattern's value is in the collaborator wiring, not in class-level immutability.

### Decision: `ClientInterface` is not touched

The interface already declares only `getLastRequest`, `getLastResponse`, `request`, `get`, and the four endpoint accessors. The setters were always implementation-only. So consumers typing against `ClientInterface` see no signature diff.

## Risks / Trade-offs

- **Test fan-out** → all ≈20 test files that currently call `$client->setHttpClient($http)` need rewriting to use named ctor arguments. Mechanical, but tedious. *Mitigation:* one batch in this change; the rewrite is a sed-style transform (`new Client('demo', 'demo'); $client->setHttpClient($http)` → `new Client('demo', 'demo', httpClient: $http)`).
- **Eager discovery in tests** → a test that constructs `Client` without injecting an HTTP client will now run discovery in `__construct`. If `php-http/discovery` can't find an implementation in the test environment, construction fails immediately instead of at first `sendRequest`. *Mitigation:* `nyholm/psr7` and `symfony/http-client` are already dev-deps, so discovery has implementations to find. The eager failure is arguably a clarity improvement.
- **Six positional arguments** → consumers who don't use named arguments would need to pass `null, null, null` to set the mapper builder. *Mitigation:* PHP 8.1+ named arguments make this a non-issue, and the documented call style in README will use named args.
- **YAGNI on `StreamFactoryInterface`** → unused today. *Mitigation:* the future write-endpoint change is a known direction; pre-wiring saves a second BC break.
- **BC break for the v2 setter API** → any consumer who pinned `dev-1.x` and built against setters will break. *Mitigation:* 1.x has not been released, so no stable consumer exists yet. README and release notes will document the migration.

## Migration Plan

Single-shot migration; no phased rollout because 1.x isn't released.

1. Update `Client::__construct` signature and remove the three setter methods.
2. Replace `Http\Discovery\Psr18Client` with `Http\Discovery\Psr18ClientDiscovery::find()` in the wiring.
3. Add `StreamFactoryInterface` resolution path (stored, not consumed).
4. Refactor every test file under `tests/` that uses a setter to construct the `Client` with named arguments instead. Verify the project's test doubles (`ScriptedHttpClient`, `FixedStatusHttpClient`) still satisfy `Psr\Http\Client\ClientInterface`.
5. Update README:
   - Make the existing constructor-with-`MapperBuilder` example match the real signature.
   - Add a "Bringing your own HTTP client" section that shows zero-config discovery, injecting a Symfony PSR-18 client, and wrapping for logging/retries.
6. PHPStan at `level: max` + ECS check + full PHPUnit suite must pass.
7. No rollback needed — pure code+test refactor, no data/migration concerns.
