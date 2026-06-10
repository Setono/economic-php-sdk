## Why

The SDK has draft-order POST support from `create-draft-order` but no customers surface at all — no read endpoint, no DTOs, no `$client->customers()` accessor. Customers are foundational: every order, every invoice, every quote references a customer by number. Adding the customers capability now (a) makes the SDK useful for end-to-end create-customer-then-create-order flows and (b) hits the architectural moment the `create-draft-order` design explicitly punted on: *"When the second write endpoint lands, refactor [`create()` into the shared tier]."*

This change adds the customers capability end-to-end (read + write) AND extracts a shared `ResourceEndpoint::createOne()` helper so the third write endpoint (and beyond) gets the pattern for free.

## What Changes

- **New consumer-facing capability**: `$client->customers()` returns a `CustomersEndpoint extends CollectionEndpoint<Customer>` exposing the standard four methods inherited or specialized:
  - `create(CustomerRequest $req): Customer` — POST a typed payload
  - `getByNumber(int $number): ?Customer` — GET by id, 404 → null
  - `getPage(?CollectionRequestOptions): Collection<Customer>` — single page
  - `paginate(?CollectionRequestOptions): \Generator<Customer>` — walk all pages
- **New request DTO** `Setono\Economic\Request\Customer\CustomerRequest` — flat (no nested DTOs needed; the schema decomposes into ~5 required scalars + 5 required identifier references + 17 optional scalars + 2 optional identifier references). `final readonly implements Payload`. Required fields non-nullable; optionals default `null`.
- **New response DTO** `Setono\Economic\Response\Customer\Customer extends Resource` — 15 typed scalar fields covering identity / metadata / contact / address / financial state. Everything else (reference objects, HATEOAS link blobs, niche scalars) lives in `$raw`.
- **New `Identifier::customerGroup(int): self`** factory — the one schema reference type not already covered by the existing 12 factories.
- **Refactor (small)**: extract `ResourceEndpoint::createOne(Payload $request): T` as a `protected` helper parallel to the existing `getOne()`. `DraftOrdersEndpoint::create()` collapses from 5-ish lines to a one-line delegate. `CustomersEndpoint::create()` lands as the same one-liner. The pattern is then codified for future write endpoints.
- **BC**: the only public-surface addition is `Client::customers()`. Additive — no existing call sites break.
- **Documentation**: README gains a "Creating a customer" snippet next to the "Creating a draft order" one; CLAUDE.md updates the endpoint inventory and notes the `createOne()` shared helper.

## Capabilities

### New Capabilities

(none — this change extends two existing capabilities)

### Modified Capabilities

- `endpoint-api`: gains the `CustomersEndpoint`, the `Customer` response DTO, `Identifier::customerGroup` factory, the `CustomerRequest` DTO, the `createOne()` helper on `ResourceEndpoint`, and the `Client::customers()` accessor. `DraftOrdersEndpoint::create()` is modified to delegate to the new helper.
- `http-transport`: not modified — `Client::post()` is unchanged. The customer create path reuses it as-is.

## Impact

- **Affected code**:
  - New: `src/Client/Endpoint/CustomersEndpoint.php`, `src/Response/Customer/Customer.php`, `src/Request/Customer/CustomerRequest.php`.
  - Modified (add factory): `src/Request/Identifier.php` (one new factory + small spec delta).
  - Modified (refactor): `src/Client/Endpoint/ResourceEndpoint.php` (gains `createOne()`), `src/Client/Endpoint/Orders/DraftOrdersEndpoint.php` (`create()` becomes a one-liner), `src/Client/Client.php` (`customers()` accessor), `src/Client/ClientInterface.php` (`customers()` method declaration).
- **Affected tests**:
  - New: `tests/Request/Customer/CustomerRequestTest.php` (constructor assertions), `tests/Client/Endpoint/CustomersCreateTest.php` (end-to-end POST), `tests/Client/Endpoint/CustomersLookupTest.php` (getByNumber 200 + 404), `tests/Client/Endpoint/CustomersPaginationTest.php` (mirror of pagination tests for the new leaf).
  - Modified: `tests/Request/IdentifierTest.php` (data provider gains `customerGroup`); existing draft-orders create tests stay green (the `createOne()` refactor must be transparent).
- **Affected docs**: `README.md` (new "Creating a customer" section), `CLAUDE.md` (endpoint inventory + `createOne()` mention).
- **Dependencies**: none. Same `composer.json`.
- **`priceGroup` open question**: the raw POST schema describes `priceGroup` as `{ self: string (uri) }` — no `priceGroupNumber` field, breaking the universal `{<x>Number: int}` convention. This change OMITS `priceGroup` from the request DTO; consumers needing to set it can drop down to `Client::post()` with a hand-built array. Documented as a known gap in `design.md`.
