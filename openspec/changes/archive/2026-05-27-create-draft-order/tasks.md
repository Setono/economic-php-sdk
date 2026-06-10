## 1. Foundation — `Identifier` value object + `Payload` marker

- [x] 1.0 Create `src/Request/Payload.php` — empty marker interface used by the normalizer's null-skipping transformer to identify request DTOs that need their null properties stripped during JSON serialization.
- [x] 1.1 Create `src/Request/Identifier.php` as `final readonly class` with private constructor `(string $fieldName, int $value)`. Assert `$fieldName` is non-empty and `$value` is positive in the constructor (`Webmozart\Assert\Assert`).
- [x] 1.2 Add the 11 named static factories: `layout`, `paymentTerms`, `customer`, `vatZone`, `project`, `deliveryLocation`, `product`, `unit`, `employee`, `customerContact`, `vendor`. Each one-liner: `return new self('<x>Number', $n);`.
- [x] 1.3 Add a static helper `Identifier::registerTransformer(NormalizerBuilder $builder): NormalizerBuilder` that calls `$builder->registerTransformer(static fn (Identifier $id): array => [$id->fieldName => $id->value])` and returns the builder. Used both internally by `Client::defaultNormalizerBuilder()` and externally by consumers wiring their own builder. Note: `NormalizerBuilder::registerTransformer()` is `@pure` and clones, so the return value MUST be used.

## 2. Request DTOs for the `POST /orders/drafts` body

- [x] 2.1 Create `src/Request/Order/Recipient.php` — `final readonly implements Payload`, required `string $name` (assert non-empty) + required `Identifier $vatZone`, plus optionals: `?string $address`, `?string $zip`, `?string $city`, `?string $country`, `?string $ean`, `?string $publicEntryNumber`, `?Identifier $attention` (via `Identifier::customerContact()`), `?string $mobilePhone`, `?NemHandelType $nemHandelType` (PHP enum).
- [x] 2.2 Create `src/Request/Order/Delivery.php` — `final readonly implements Payload`, every field optional: `?string $address`, `?string $zip`, `?string $city`, `?string $country`, `?string $deliveryTerms`, `?string $deliveryDate`.
- [x] 2.3 Create `src/Request/Order/Notes.php` — `final readonly implements Payload`, optional `?string $heading`, `?string $textLine1`, `?string $textLine2`.
- [x] 2.4 Create `src/Request/Order/References.php` — `final readonly implements Payload`, optional `?Identifier $salesPerson` (via `Identifier::employee()`), `?Identifier $customerContact` (via `Identifier::customerContact()`), `?Identifier $vendorReference` (via `Identifier::vendor()`), `?string $other`.
- [x] 2.5 Create `src/Request/Order/Line.php` — `final readonly implements Payload`. All fields optional (the schema's "description required for existing products" rule is server-enforced): `?int $lineNumber`, `?int $sortKey`, `?string $description`, `?Accrual $accrual`, `?Identifier $unit`, `?Identifier $product`, `?float $quantity`, `?float $unitNetPrice`, `?float $discountPercentage`, `?float $unitCostPrice`, `?Identifier $departmentalDistribution`. Read-only computed fields (`marginInBaseCurrency`, `marginPercentage`) are intentionally omitted — they belong on the response.
- [x] 2.5.a Create `src/Request/Order/Accrual.php` — `final readonly implements Payload`, optional `?string $startDate`, `?string $endDate` (ISO-8601 dates). Used by `Line::$accrual`.
- [x] 2.5.b Create `src/Request/Order/NemHandelType.php` — backed `string` enum with cases `Ean`, `CorporateIdentificationNumber`, `PNumber`, `Peppol` (values from the schema verbatim). Used by `Recipient::$nemHandelType`.
- [x] 2.5.c Update `src/Request/Identifier.php` — `$value` is `int|string` (schema's `productNumber` is a string). Add a 12th factory `Identifier::departmentalDistribution(int)`. The `product(string $n)` factory accepts string; all others accept `int`. `Assert::notEmpty` covers both type branches; `Assert::positive` only fires for ints.
- [x] 2.6 Create `src/Request/Order/DraftOrderRequest.php` — `final readonly implements Payload`, required `string $date` / `string $currency` / `Identifier $layout` / `Identifier $paymentTerms` / `Identifier $customer` / `Recipient $recipient`. Optional: `?float $exchangeRate`, `?string $dueDate`, `?Identifier $project`, `?Identifier $deliveryLocation`, `?Delivery $delivery`, `?Notes $notes`, `?References $references`, `?list<Line> $lines` — all default `null`. Assert `$date` non-empty; assert `$currency` non-empty AND `Assert::length($currency, 3)`.

## 3. Default normalizer wiring on `Client`

- [x] 3.1 Add `?NormalizerBuilder $normalizerBuilder = null` as the 7th named ctor parameter on `Client::__construct`. Promote it to a `private readonly NormalizerBuilder` property after resolution.
- [x] 3.2 Add `Client::defaultNormalizerBuilder(): NormalizerBuilder` (private static) that constructs a fresh `NormalizerBuilder`, calls `Identifier::registerTransformer($builder)` (capturing the returned builder — `@pure` clones), then chains a null-skipping transformer keyed on the `Payload` marker interface: `registerTransformer(static fn (Payload $obj, callable $next): array => array_filter($next(), static fn ($v) => $v !== null))`. Returns the configured builder.
- [x] 3.3 In `__construct`, resolve `$this->normalizerBuilder = $normalizerBuilder ?? self::defaultNormalizerBuilder()`.
- [x] 3.4 Add `public function getNormalizerBuilder(): NormalizerBuilder` that returns `$this->normalizerBuilder`. Mirrors the `getStreamFactory()` pattern. NOT added to `ClientInterface`.

## 4. `Client::post()` and the `ClientInterface` addition

- [x] 4.1 Add `Client::post(string $uri, object $body): array<string, mixed>` implementing the flow in `design.md`: resolveUrl → normalizer→normalize(body) → streamFactory→createStream → requestFactory→createRequest('POST', url)->withBody(stream) → request($req) → decodeJson($req, $resp).
- [x] 4.2 Add the matching `public function post(string $uri, object $body): array;` declaration to `ClientInterface` with a PHPDoc that mirrors `get()`'s exception annotations (`@throws ClientExceptionInterface`, `@throws EconomicException`, `@throws MalformedResponseException`).
- [x] 4.3 PHPStan-prove the body type — the `Format::json()` normalizer returns a JSON string; assign it to a variable typed `string` so PHPStan can follow the chain to `streamFactory->createStream(string)`.

## 5. `DraftOrdersEndpoint::create()`

- [x] 5.1 Add `public function create(DraftOrderRequest $request): Order` to `src/Client/Endpoint/Orders/DraftOrdersEndpoint.php`.
- [x] 5.2 Implementation: `$data = $this->client->post(static::getPath(), $request); return $this->mapperBuilder->mapper()->map(Order::class, $data);` — the same map-via-Valinor pattern as `getOne()`. `$raw` is stamped by the existing `RawStamper` converter.
- [x] 5.3 PHPDoc the `@return Order` so PHPStan-max picks up the typed return. (Used inline `/** @var Order $order */` since `MapperBuilder::mapper()->map(Order::class, ...)` returns `mixed` from PHPStan's view; webmozart's `Assert::isInstanceOf` would be the "no @var" alternative but adds a runtime check for something Valinor already guarantees.)

## 6. Tests

- [x] 6.1 New test file `tests/Request/IdentifierTest.php`: every factory pins the correct `fieldName`; non-positive `int` throws; empty string `productNumber` throws; integer-vs-string factories return the right `$value` type.
- [x] 6.2 New test file `tests/Request/Order/DraftOrderRequestTest.php`: required-only construction succeeds; empty `$date` / `$currency` throws; 4-char and 2-char `$currency` throws; `Recipient` with empty `name` throws.
- [x] 6.3 New test file `tests/Client/Endpoint/Orders/DraftOrdersCreateTest.php` using `ScriptedHttpClient`:
  - request method, URI, and headers (`Content-Type: application/json`, auth, UA, X-Tokens) — ✓
  - request body JSON matches expected normalized form including: every `Identifier` serialized as `{<x>Number: int}`; every `null` optional field absent from the body; nested `Recipient.vatZone` Identifier embedded correctly — ✓
  - supplied optional fields (notes, lines with Identifier-typed product) round-trip into the body — ✓
  - response is decoded into `Order` with `$raw` populated (untyped server-computed fields like `grossAmount` accessible via `$raw`) — ✓
  - 422 response surfaces as `ValidationException` with `getErrorCode()` / `getDeveloperHint()` / `getValidationErrors()` returning the server's nested document — ✓
- [x] 6.4 ~~New test in `tests/Client/ClientTest.php`~~: `Client::post()` with a stub `Identifier`-only object produces the expected body. **Skipped as redundant** — the `DraftOrdersCreateTest` end-to-end already exercises `Client::post()` through the same Identifier serialization path; a separate stub-only test would duplicate the assertion.
- [x] 6.5 Extended the existing `zero_config_construction_resolves_collaborators_via_discovery` test in `tests/Client/ClientTest.php` to also check `normalizerBuilder` is resolved (5 properties instead of 4).
- [x] 6.6 New test `register_transformer_returns_a_builder_that_normalizes_identifier_to_keyed_pair` + `register_transformer_preserves_other_builder_configuration` in `tests/Request/IdentifierTest.php` — covers the consumer-supplied-builder contract end-to-end, including verifying the `@pure`-clones semantic doesn't drop earlier transformers.
- [x] 6.7 Also extended `client_exposes_no_collaborator_setters` to forbid a `setNormalizerBuilder()` method (locks the new collaborator into the immutability contract).

## 7. Documentation

- [x] 7.1 README — added a "Creating a draft order" section just before "Ping / who am I". Shows the minimal required-only construction and a fuller example with `Notes` + `lines` + Identifier-typed `product`. Documents the full set of `Identifier` named factories.
- [x] 7.2 README — extended the "Production usage" / caching section to show both `mapperBuilder` AND `normalizerBuilder` with caches. The snippet explicitly calls `Identifier::registerTransformer(new NormalizerBuilder()->withCache($cache))` so consumers know they need to wire the SDK's transformer onto their own builder.
- [x] 7.3 CLAUDE.md — updated the `Client` Architecture paragraph: 7 ctor args (5 optional named collaborators); new `Client::post()` JSON helper; new `getNormalizerBuilder()` getter; brief mention of `defaultNormalizerBuilder()` registering the Identifier transformer + Payload null-skipping transformer.
- [x] 7.4 CLAUDE.md — expanded the `src/Request/` paragraph (Architecture section §5) into a bulleted list covering: `CollectionRequestOptions` (read-side), `Identifier` (cross-cutting write-side with all 12 factories listed), `Payload` marker interface contract, and the `Order/*` namespace (DraftOrderRequest + nested DTOs + `NemHandelType` enum, plus the Webmozart-asserts policy).

## 8. Verify

- [x] 8.1 Run `composer phpunit` — **100 tests / 223 assertions, all green.**
- [x] 8.2 Run `composer analyse` — **PHPStan at level: max clean.** One real issue caught during apply: `Webmozart\Assert\Assert::positive()` doesn't exist on the installed version — corrected to `Assert::positiveInteger()`. One redundant `assertInstanceOf` on a statically-typed `Order` return — removed.
- [x] 8.3 Run `composer check-style` — **clean** after one auto-fix on `tests/Request/IdentifierTest.php` (BinaryOperatorSpacesFixer normalized the data-provider yield alignment).
- [x] 8.4 Run `vendor/bin/rector --dry-run` — **clean.** No new modernization suggestions.
- [ ] 8.5 Skip `vendor/bin/infection` locally — same constraint as the prior change: no pcov/xdebug for PHP 8.4 in the local environment. CI handles mutation testing.
