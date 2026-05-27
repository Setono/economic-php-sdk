## 1. Update `Client` constructor and wiring

- [x] 1.1 Add `?HttpClientInterface $httpClient`, `?RequestFactoryInterface $requestFactory`, `?StreamFactoryInterface $streamFactory`, and `?MapperBuilder $mapperBuilder` as named constructor parameters to `src/Client/Client.php`, all defaulting to `null`.
- [x] 1.2 Inside `__construct`, eagerly resolve each `null` collaborator: HTTP client via `Http\Discovery\Psr18ClientDiscovery::find()`, request factory via `Http\Discovery\Psr17FactoryDiscovery::findRequestFactory()`, stream factory via `Http\Discovery\Psr17FactoryDiscovery::findStreamFactory()`, mapper builder via a new private `static defaultMapperBuilder(): MapperBuilder` helper.
- [x] 1.3 Move the default-`MapperBuilder` configuration (the chain ending in `->registerConverter(new RawStamper())`) out of the lazy `getMapperBuilder()` into `defaultMapperBuilder()`.
- [x] 1.4 Promote the resolved collaborators to `private readonly` properties on `Client`. Drop the `null`able typing on the stored fields; resolution makes them non-null.
- [x] 1.5 Replace the `Http\Discovery\Psr18Client` import with `Http\Discovery\Psr18ClientDiscovery`. Remove any `??=` lazy paths for the HTTP client / request factory / mapper builder.
- [x] 1.6 Add the `?StreamFactoryInterface` import (`Psr\Http\Message\StreamFactoryInterface`) and store the resolved factory on a `private readonly` property even though no current internal code consumes it. PHPStan ignore-by-line is acceptable if the property is flagged as unused.

## 2. Remove the setter API

- [x] 2.1 Delete `Client::setHttpClient()`, `Client::setRequestFactory()`, and `Client::setMapperBuilder()` from `src/Client/Client.php`.
- [x] 2.2 Delete the private lazy accessors `Client::getHttpClient()`, `Client::getRequestFactory()`, and `Client::getMapperBuilder()`. Direct property reads replace them.
- [x] 2.3 Confirm `Client::request()` reads `$this->httpClient` directly (not via `getHttpClient()`), and `Client::get()` reads `$this->requestFactory` directly.
- [x] 2.4 Update each endpoint accessor (`invoices()`, `orders()`, `products()`, `self()`) to pass `$this->mapperBuilder` directly instead of `$this->getMapperBuilder()`.

## 3. Refactor tests off the setter API

- [x] 3.1 In every test file under `tests/` that currently calls `$client->setHttpClient($http)` (the grep already lists ~10 files: `tests/Response/ResourceRawTest.php`, `tests/Exception/ExceptionHierarchyTest.php`, `tests/Client/ClientTest.php`, `tests/Client/Endpoint/EndpointLookupTest.php`, `tests/Client/Endpoint/PaginationTest.php`, and any others surfaced by `grep -rn setHttpClient tests/`), rewrite the construction line to use named arguments: `new Client('demo', 'demo', httpClient: $http)`. (Also refactored `bin/paginate.php` which had a setter call.)
- [x] 3.2 In any test that calls `$client->setMapperBuilder($custom)`, rewrite the construction to inject the mapper builder via named argument: `new Client('demo', 'demo', mapperBuilder: $custom)`. Special-case the test at `tests/Client/ClientTest.php:243` which exercises the setter — refactor it to assert the constructor-injected builder is the one endpoints receive.
- [x] 3.3 Add a new test asserting zero-config construction wires up discovered defaults (`new Client('demo', 'demo')` succeeds and `Client::request()` dispatches through whatever PSR-18 client `Psr18ClientDiscovery::find()` returned in the test environment — `nyholm/psr7` + `symfony/http-client` are dev-deps so discovery resolves).
- [x] 3.4 Add a test asserting the consumer-supplied `StreamFactoryInterface` is stored on the `Client` (reflection-based check is acceptable — the property is non-public). This guards against a regression where the parameter is accepted but discarded.
- [x] 3.5 Add a test asserting that none of `setHttpClient`, `setRequestFactory`, `setStreamFactory`, `setMapperBuilder` exists on the `Client` class (reflection-based). This locks the immutability requirement.

## 4. Update documentation

- [x] 4.1 In `README.md`, update the "Performance" snippet so the example `new Client('API_KEY', 'API_SECRET', (new MapperBuilder())->withCache($cache))` compiles against the new signature (it should use `mapperBuilder:` as the named argument since `$httpClient` comes first in the parameter list).
- [x] 4.2 In `README.md`, add a "Bringing your own HTTP client" section that shows: (a) zero-config discovery; (b) injecting a Symfony PSR-18 client via `Psr18Client(HttpClient::create()->withOptions(['max_retries' => 3]))`; (c) the existing tip from the deprecation table — "wrap your PSR-18 client to log" — moved/expanded into this section.
- [x] 4.3 In `CLAUDE.md`, update the `Client` description in the "Architecture" section so the constructor signature paragraph matches the new shape (six parameters; no setters).

## 5. Verify the change

- [x] 5.1 Run `composer analyse` — PHPStan at level: max must pass with the new signature and removed methods. *(passed, after adding a public `Client::getStreamFactory()` so PHPStan no longer flags the property as written-only — fix matches the Sensiolabs example and is documented in design.md, but the addition is a small surface increment worth flagging in the change log.)*
- [x] 5.2 Run `composer phpunit` — the full test suite must pass after the test refactor. *(68 tests / 149 assertions, all green.)*
- [x] 5.3 Run `composer check-style` — ECS must pass; auto-fix with `composer fix-style` if needed. *(clean, no fixes needed.)*
- [x] 5.4 Run `vendor/bin/rector --dry-run` — confirm no new modernization suggestions are introduced by the refactor. *(clean.)*
- [ ] 5.5 Run `vendor/bin/infection` — MSI must stay above the configured thresholds (53.85 / 79.25 covered). New tests added in §3 should improve, not degrade, the score. *(skipped locally: neither pcov nor xdebug is installed for the active PHP 8.4 — Infection cannot generate coverage. CI will exercise this; pinning to CI gate.)*

## 6. Scope additions discovered during apply

- [x] 6.1 Hoist `Client::getBaseUri()` into a typed `private const string BASE_URI` (no overrides, no logic — was a method only by historical accident). Update the two call sites in `resolveUrl()` accordingly and drop the now-unreachable `?? '(unparseable)'` fallback on `$baseHost` (PHPStan proved it dead).
- [x] 6.2 Make `Client::decodeJson()` `private static` by threading the `RequestInterface` in as an explicit parameter and inlining the request-context formatting. Delete the now-unused `Client::requestContext()` instance method. *(`Client::excerpt()` was already `private static`; no change there.)*
- [x] 6.3 In `Client::resolveUrl()`, mirror the absolute-URL "URI + `$query`" rejection on the relative-path branch: if `$uri` contains `?` AND `$query` is non-empty, throw `InvalidUrlException`. Add two test cases (rejection + the still-allowed embedded-query-with-empty-`$query` path). Captured as a new requirement in the `http-transport` spec delta.
- [x] 6.4 Expose request/response journaling via PHP 8.4 properties instead of getter methods. `Client::$lastRequest` and `Client::$lastResponse` become `public private(set)`; the `getLastRequest()` / `getLastResponse()` accessor methods on both `Client` and `ClientInterface` are removed. The interface declares the read seam via virtual properties (`public ?RequestInterface $lastRequest { get; }`, same for `$lastResponse`). Updated test call site (`tests/Client/ClientTest.php`) to read `$client->lastRequest` / `$client->lastResponse` directly. Added a `pristine_client_journals_null` test. The "Request lifecycle and journaling" requirement in the `http-transport` spec delta gets a MODIFIED block reflecting the new shape. *(Considered an "external assignment is rejected" test, but it would require `@phpstan-ignore assign.propertyPrivateSet` to compile under PHPStan level: max — dropped since the rejection is a PHP 8.4 language guarantee and the project policy is to not silence PHPStan.)*
