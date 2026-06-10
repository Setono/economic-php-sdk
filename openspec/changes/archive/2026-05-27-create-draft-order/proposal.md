## Why

The SDK currently exposes only read endpoints. Adding `POST /orders/drafts` (per [schema](https://restapi.e-conomic.com/schema/orders.drafts.post.schema.json)) is the first write endpoint and therefore sets the patterns that every subsequent write endpoint will inherit: how consumers describe the payload, how the body is serialized, how `Client` exposes a write-side helper, how the endpoint method names what it does. This change is foundational, not incidental — it pays the design tax for the SDK's write surface so future endpoints (`POST /products`, `PUT /orders/drafts/:n`, …) are mechanical.

## What Changes

- **New consumer-facing capability**: `$client->orders()->drafts()->create(DraftOrderRequest $req): Order` posts a typed request DTO and returns the created `Order` (with `$raw` populated from the response).
- **New typed request-DTO surface** under `src/Request/Order/` mirroring the read DTOs:
  - `DraftOrderRequest` — top-level POST body. Required fields are non-nullable constructor args; optional fields default to `null` and are omitted from the serialized JSON.
  - `Recipient`, `Delivery`, `Notes`, `References`, `Line` — typed nested objects for the schema's structured fields.
- **New cross-cutting `Setono\Economic\Request\Identifier`** — a single generic foreign-key wrapper used wherever the schema accepts a `{<x>Number: int}` reference. Eleven named factories (`Identifier::layout()`, `::paymentTerms()`, `::customer()`, `::vatZone()`, `::project()`, `::deliveryLocation()`, `::product()`, `::unit()`, `::employee()`, `::customerContact()`, `::vendor()`) cover every reference type the schema exercises. Replaces what would otherwise be 11 separate reference classes.
- **Valinor normalizer wiring**: a `Setono\Economic\Mapper\IdentifierTransformer` (or registered closure) outputs `[$id->fieldName => $id->value]` for every `Identifier`. A separate chained transformer skips `null` properties so optional fields are absent (not `null`) in the JSON sent to e-conomic.
- **BREAKING**: `Client::__construct` gains a 7th optional named argument, `?NormalizerBuilder $normalizerBuilder = null`. Valinor 2.x's normalizer is built from `CuyZ\Valinor\NormalizerBuilder` — a separate type from `MapperBuilder` — so the BYOHC-style pluggable surface needs its own slot. When omitted, the SDK constructs a default builder with the Identifier transformer and null-skipping transformer pre-registered. Adding the parameter is the canonical "extend the BYOHC surface" move; same shape as `mapperBuilder`.
- **New low-level helper**: `Client::post(string $uri, object $body): array<string, mixed>` — symmetric to `Client::get()`. Strictly typed: takes an `object` and normalizes it via Valinor; consumers wanting raw-array payloads use `Client::request()` directly. This is where the pre-wired `$streamFactory` finally earns its keep.
- Server-side validation only. The SDK does not duplicate the schema's structural rules. `Assert::notEmpty($string)` / `Assert::positive($int)` are acceptable at DTO construction time; format / enum / conditional-required checks belong to e-conomic.

## Capabilities

### New Capabilities

(none — this change extends two existing capabilities rather than introducing a new one)

### Modified Capabilities

- `http-transport`: gains a new `Client::post()` helper, a new `?NormalizerBuilder $normalizerBuilder` constructor parameter (with eager default construction when null), and a corresponding `getNormalizerBuilder()` getter (matching the symmetry with `getStreamFactory()` so the normalizer is consumable by future write endpoints).
- `endpoint-api`: gains a `create(DraftOrderRequest $req): Order` method on `DraftOrdersEndpoint`. The shared `Endpoint` / `ResourceEndpoint` / `CollectionEndpoint` tier is **not** changed — `create()` is added directly to the leaf endpoint. When the second write endpoint lands, a shared `WritableEndpoint` (or analogous) refactor can be considered; pre-abstracting now would invent structure with a single user.

## Impact

- **Affected code**: `src/Client/Client.php` (new constructor parameter, default `NormalizerBuilder`, new `getNormalizerBuilder()` getter, new `post()` method); `src/Client/ClientInterface.php` (new `post()` method declaration; `getNormalizerBuilder()` likely stays off the interface, like `getStreamFactory()`); `src/Client/Endpoint/Orders/DraftOrdersEndpoint.php` (new `create()` method); new files under `src/Request/Order/*` and `src/Request/Identifier.php` and `src/Mapper/IdentifierTransformer.php` (or equivalent).
- **Affected tests**: new test file covering `DraftOrdersEndpoint::create()` end-to-end (Identifier serialization, null-skipping, response decoding into `Order`). Probably reuse the project's `ScriptedHttpClient` test double for asserting request bodies.
- **Affected docs**: `README.md` gains a "Creating a draft order" example; `CLAUDE.md` Architecture section gets an updated `Client` paragraph (now 7 ctor args, mentions normalizer wiring) plus a brief mention of the new `src/Request/` shape.
- **Dependencies**: no `composer.json` changes. Valinor 2.x is already required and ships the normalizer.
- **BC promise**: 7th constructor parameter is purely additive (default `null`), but the `ClientInterface` gains a new method — that IS a BC break for anyone implementing the interface. Acceptable because 1.x has not been released yet.
- **Performance note**: the `NormalizerBuilder` benefits from the same cache story as the `MapperBuilder`. README will document this for consumers building their own.
