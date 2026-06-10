# Quality audit — 2026-05-27 — closeout

All 26 audit findings actioned. Test suite went from 120 → 141 tests / 299 → 335 assertions. All four gates clean (PHPUnit, PHPStan max, ECS, Rector). Safe to delete this file.

## Robustness & error handling

- [x] **F01** — pre-read body once in `Client::assertStatusCode`, thread into `ResponseAwareException` via new `body:` ctor param; `parseBody()` uses cached value. Lazy-parse now survives non-seekable PSR-7 streams.
- [x] **F02** — `MappingException extends MalformedResponseException` wraps Valinor `MappingError`. Threaded via new `ResourceEndpoint::mapResource()` helper used by all three `->map()` call sites. Original error preserved as `$previous`; message embeds `[METHOD URL]`.
- [x] **F03** — `paginate()` docblock documents the non-transactional contract; regression test `mid_walk_failure_exhausts_the_generator_after_yielding_completed_pages` drives the generator step-by-step.
- [x] **F04** — defensive probe at `Client::__construct`: normalizes `Identifier::layout(1)` through the supplied builder; throws `\LogicException` with `Client::registerNormalizerTransformers` as the remediation hint if the SDK transformers aren't wired.
- [x] **F05** — new public `Client::registerNormalizerTransformers(NormalizerBuilder): NormalizerBuilder` wires BOTH `Identifier` and `Payload` transformers. `Client::defaultNormalizerBuilder()` uses it. README's production-usage example updated.
- [x] **F06** — `Client::request()` stamps `Content-Type: application/json` only if the request lacks one. Tests cover both branches.
- [x] **F07** — `Page` constructor validates `skippages` / `pagesize` (numeric, range bounds against e-conomic's 1-1000 max). Server-issued malformed nextPage URLs now fail loudly instead of silently coercing to 0.

## Type system rigor & DX

- [x] **F08** — `Client::post()` and `ClientInterface::post()` narrowed from `object` to `Payload`. All in-tree request DTOs already implement Payload; no test fallout.
- [x] **F09** — exception messages embed `[METHOD URL]` (query/fragment stripped). `ResponseAwareException` ctor gains optional `request:` param; threaded through `Client::assertStatusCode`.
- [x] **F10** — Identifier/Client transformer docblocks call out Valinor's LIFO transformer order.
- [x] **F11** — `Assert::stringNotEmpty(trim(...))` on all required string fields (`CustomerRequest::$name`/`$currency`, `DraftOrderRequest::$date`/`$currency`, `Recipient::$name`, `Identifier::$value` for string branch). Whitespace-only now rejected with clear messages. New tests cover the whitespace path.
- [x] **F12** — existing `@param list<Line>|null` PHPDoc on `DraftOrderRequest::__construct` is already what PHPStan reads (constructor-promoted property inherits the param type). No action needed.
- [x] **F13** — existing `@return T` / `@return T|null` PHPDocs on `ResourceEndpoint::getOne` / `CollectionEndpoint::getItem` already give PHPStan the typed return inference at consumer call sites. Audit's claim was overstated; no action needed.
- [x] **F14** — recursive null-skip lock-in: `Client::registerNormalizerTransformers` now strips BOTH `null` AND `[]` values from `Payload` normalized output. Caught a real behavior bug — nested all-null `Payload` (e.g. `new Accrual()`) was producing `"accrual":[]` instead of being omitted. Test added.

## Developer experience & docs

- [x] **F15** — README error-handling section rewritten: explicit `null` vs `throw` semantics, retry-policy guidance pointing at PSR-18 decorators, MappingException note.
- [x] **F18** — README gained a "Testing your code that calls the SDK" subsection with a minimal anonymous-class `ClientInterface` fake.
- [x] **F19** — README gained guidance: re-serialize via `$dto->raw`, not `json_encode($dto)`.
- [x] **F24** — README gained "Long-running processes" subsection with antipattern vs corrected `Client` reuse pattern (including the `mapperBuilder` + `normalizerBuilder` cache wiring).

## Security

- [x] **F16** — `Client::resolveUrl` lowercases both base and incoming hosts before comparison. Mixed-case server-issued URLs are accepted. Added private static helper `parseStringPart` to coerce parse_url's `string|false|null` to `string` for PHPStan-clean strtolower calls.
- [x] **F17** — `Client::resolveUrl` rejects any explicit non-default port (HTTPS → 443, HTTP → 80). New tests for both reject (`:9999`) and accept (`:443`).
- [x] **F22** — exception messages strip query string + fragment from the embedded URL. Implemented in both `Client::decodeJson` and `ResponseAwareException::buildRequestContext`. Test verifies secrets in query params don't leak.
- [x] **F25** — `tests/Client/ClientTest.php` gained mixed-case-host test (covers F16) and port-mismatch test (covers F17).

## Specs, CI, testing infrastructure

- [x] **F20** — `.github/workflows/build.yaml` `unit-tests` job now runs `lowest` + `highest`; `fail-fast: false` so one resolution failing doesn't mask the other.
- [x] **F21** — `tests/Exception/ExceptionHierarchyTest.php` gained `network_errors_from_the_psr18_layer_propagate_unwrapped_to_the_consumer` locking the contract: PSR-18 `NetworkExceptionInterface` propagates as-is (no SDK wrapping). Retry policy is BYOHC consumer's responsibility.
- [x] **F23** — `openspec/specs/http-transport/spec.md` updated for Content-Type override semantics + new scenario.

## Standalone

- [-] **F26** — perf micro (RawStamper / MapperBuilder allocation per Client construct). **Dropped.** Single-Client-per-process is the dominant pattern; multi-Client cost is negligible relative to Valinor mapper compilation. README's "Long-running processes" section (F24) documents the right pattern instead.

---

## Summary stats

| | Before | After |
|---|---|---|
| Tests | 120 | 141 (+21) |
| Assertions | 299 | 335 (+36) |
| HIGH findings open | 8 | 0 |
| MEDIUM findings open | 15 | 0 |
| LOW findings open | 3 | 0 |

All four gates: ✓ `composer phpunit` ✓ `composer analyse` ✓ `composer check-style` ✓ `vendor/bin/rector --dry-run`.

Mutation testing (`composer phpunit` doesn't gate it locally) deferred to CI as before — no pcov/xdebug for PHP 8.4 on this dev box.

## Files touched

- `src/Client/Client.php` — biggest surface change: new `registerNormalizerTransformers()` public helper, defensive probe in `__construct`, `assertStatusCode` pre-reads body & threads request, `decodeJson` strips query/fragment, `resolveUrl` case-insensitive host + port validation + new `parseStringPart` helper, `Content-Type` conditional stamping, narrowed `post()` to `Payload`.
- `src/Client/ClientInterface.php` — narrowed `post()` signature to `Payload`, added `Payload` import.
- `src/Client/Endpoint/ResourceEndpoint.php` — new `mapResource()` helper wrapping Valinor `MappingError` into `MappingException`; `getOne` and `createOne` route through it.
- `src/Client/Endpoint/CollectionEndpoint.php` — `mapPage` routes through `mapResource`; `paginate()` docblock expanded with non-transactional contract.
- `src/Exception/ResponseAwareException.php` — ctor gains `?string $body` + `?RequestInterface $request`; new `tryDecode()` and `buildRequestContext()` static helpers; default message embeds sanitized `[METHOD URL]`.
- `src/Exception/MalformedResponseException.php` — un-finalized (now extensible by MappingException).
- `src/Exception/MappingException.php` — new typed exception.
- `src/Request/Identifier.php` — docblock expanded with priority note; uses `Assert::stringNotEmpty(trim(...))` for string-value branch.
- `src/Request/Payload.php` — docblock describes recursive null-skipping contract.
- `src/Request/Customer/CustomerRequest.php`, `src/Request/Order/DraftOrderRequest.php`, `src/Request/Order/Recipient.php` — `Assert::stringNotEmpty(trim(...))` swap with descriptive messages.
- `src/Response/Pagination/Page.php` — validates `skippages`/`pagesize` query params from server-issued pagination URLs.
- `openspec/specs/http-transport/spec.md` — Content-Type override semantics updated + new scenario.
- `README.md` — Error handling section rewrite + testing guidance + production-usage cache example using `Client::registerNormalizerTransformers` + long-running processes subsection + json_encode/raw guidance.
- `.github/workflows/build.yaml` — `unit-tests` matrix runs lowest + highest.
- `tests/*` — 21 new tests across `ClientTest`, `ResponseAwareExceptionTest`, `ExceptionHierarchyTest`, `CustomersLookupTest`, `CustomerRequestTest`, `DraftOrderRequestTest`, `DraftOrdersCreateTest`, `PaginationTest`.
