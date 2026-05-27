## Why

The `Client` currently wires its PSR-18 HTTP client, PSR-17 request factory, and Valinor `MapperBuilder` through post-construction setters (`setHttpClient`, `setRequestFactory`, `setMapperBuilder`). This deviates from the "bring your own HTTP client" pattern that the PHP ecosystem has converged on (see [Sensiolabs, 2025](https://sensiolabs.com/blog/2025/bring-your-own-http-client)), where collaborators are constructor-injected with auto-discovery as the default. The README already documents `MapperBuilder` as a constructor argument even though the constructor doesn't accept one — proof the intended shape diverged from what shipped. Aligning to constructor injection now (before 1.x is released) closes that gap, makes the surface idiomatic, and removes the mutable post-construction seam.

## What Changes

- **BREAKING**: `Client::__construct()` gains four optional collaborator parameters: `?HttpClientInterface $httpClient`, `?RequestFactoryInterface $requestFactory`, `?StreamFactoryInterface $streamFactory`, `?MapperBuilder $mapperBuilder`. All default to `null` and resolve via `php-http/discovery` (`Psr18ClientDiscovery::find()`, `Psr17FactoryDiscovery::findRequestFactory()`, `Psr17FactoryDiscovery::findStreamFactory()`) and an internal default `MapperBuilder`.
- **BREAKING**: Remove `Client::setHttpClient()`, `Client::setRequestFactory()`, `Client::setMapperBuilder()`. The `Client` becomes immutable with respect to its collaborators after construction.
- **BREAKING**: Replace the lazy `new Psr18Client()` facade with eager `Psr18ClientDiscovery::find()` so the resolved client is transparent at construction time.
- Add a `?StreamFactoryInterface` parameter and store it on the client even though no current internal code uses it; this pre-wires the BYOHC surface for forthcoming write endpoints (POST/PUT) without a second constructor break.
- Update all internal call sites (none today, but the constructor wiring lives in `Client::__construct`) and rewrite tests that currently use `$client->setHttpClient(...)` to use named constructor arguments.
- Refresh the README's "Performance" example so the snippet that already shows constructor injection of `MapperBuilder` matches the real constructor signature, and add a "Bringing your own HTTP client" section that demonstrates injecting a Symfony PSR-18 client (with retries/logging composed externally).

## Capabilities

### New Capabilities

(none — this change refines an existing capability rather than introducing a new one)

### Modified Capabilities

- `http-transport`: the "Pluggable PSR-18 client and PSR-17 request factory" requirement changes from setter-based wiring to constructor injection, gains a stream factory and mapper builder parameter, and gains an immutability guarantee.

## Impact

- **Affected code**: `src/Client/Client.php` (constructor signature, removal of setter methods, removal of `getMapperBuilder()` lazy path, replacement of `new Psr18Client()` with `Psr18ClientDiscovery::find()`). `src/Client/ClientInterface.php` is unaffected — it already does not expose the setters.
- **Affected tests**: every test currently calling `$client->setHttpClient($http)` or `$client->setMapperBuilder($mb)` (≈20 files under `tests/`) must construct the `Client` with named arguments instead. No behavioural change — same fake PSR-18 clients are injected, just via the constructor.
- **Affected docs**: `README.md` Performance/Caching snippet (already shows the constructor shape but doesn't compile), plus a new BYOHC section.
- **Dependencies**: no `composer.json` changes. The virtual packages (`psr/http-client-implementation`, `psr/http-factory-implementation`) and `php-http/discovery` are already required.
- **Public API**: BC break for any consumer using the setters. Acceptable because 1.x has not been released yet — the v2 redesign is the first stable surface and BYOHC ships as part of that release.
- **`ClientInterface`**: unchanged. The interface never declared the setters, so consumers typing against it see no diff.
