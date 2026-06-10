## ADDED Requirements

### Requirement: `DraftOrdersEndpoint` exposes `create(DraftOrderRequest): Order`

In addition to the inherited collection methods, `DraftOrdersEndpoint` SHALL expose `create(DraftOrderRequest $request): Order`. The method MUST: (1) call `$this->client->post(static::getPath(), $request)` to dispatch the POST, (2) map the decoded response into an `Order` via the same `MapperBuilder` pipeline used by `getByNumber()` (so `$raw` is stamped on the returned `Order`), and (3) return the typed `Order`. The 404-to-null shortcut used by `getByNumber()` MUST NOT apply: any non-2xx response propagates as the appropriate typed exception (`ValidationException` for 400/422, etc.).

#### Scenario: Successful creation returns a typed Order

- **WHEN** the consumer calls `$client->orders()->drafts()->create($request)` with a valid `DraftOrderRequest`
- **AND** the API returns 201 with the created draft order's JSON
- **THEN** an `Order` instance is returned, populated from the response
- **AND** `Order::$raw` is the full decoded response body

#### Scenario: Server-side validation failure surfaces as ValidationException

- **WHEN** the API returns 400 or 422 with an e-conomic validation document
- **THEN** `ValidationException` is thrown
- **AND** `$e->getValidationErrors()` returns e-conomic's nested validation document verbatim

#### Scenario: create() goes through Client::post

- **WHEN** `DraftOrdersEndpoint::create($request)` is called
- **THEN** exactly one HTTP request is dispatched
- **AND** that request's method is `POST` and URI is `https://restapi.e-conomic.com/orders/drafts`
- **AND** the request body is the JSON normalized form of `$request`

### Requirement: `Identifier` is the single foreign-key wrapper for request DTOs

The package SHALL provide `Setono\Economic\Request\Identifier`, a `final readonly` class that wraps an integer pointer to another resource together with the JSON field name that pointer occupies in the e-conomic schema. The constructor is `private`; the only construction path is via named static factories — one per reference type the schema exercises. `Identifier::registerTransformer(NormalizerBuilder $builder): NormalizerBuilder` SHALL be provided so consumers supplying their own `NormalizerBuilder` can append the SDK's normalization rule.

The factories that MUST exist:
- `Identifier::layout(int): self` → `layoutNumber`
- `Identifier::paymentTerms(int): self` → `paymentTermsNumber`
- `Identifier::customer(int): self` → `customerNumber`
- `Identifier::vatZone(int): self` → `vatZoneNumber`
- `Identifier::project(int): self` → `projectNumber`
- `Identifier::deliveryLocation(int): self` → `deliveryLocationNumber`
- `Identifier::product(string): self` → `productNumber` (the schema's `productNumber` is a string, max 25 chars — unlike every other identifier, which is an `int`)
- `Identifier::unit(int): self` → `unitNumber`
- `Identifier::employee(int): self` → `employeeNumber`
- `Identifier::customerContact(int): self` → `customerContactNumber`
- `Identifier::vendor(int): self` → `vendorNumber`
- `Identifier::departmentalDistribution(int): self` → `departmentalDistributionNumber`

Each factory MUST validate its argument: the 11 integer factories assert the argument is a positive integer (`Webmozart\Assert\Assert::positiveInteger`); the `product` factory asserts its string argument is non-empty (`Webmozart\Assert\Assert::notEmpty`).

#### Scenario: Each factory pins a `<x>Number` field name

- **WHEN** code calls one of `Identifier::layout(17)`, `Identifier::customer(1)`, …
- **THEN** the returned instance carries `fieldName === '<x>Number'` matching the factory name and `value === 17` (or `1`, etc.)

#### Scenario: Construction with non-positive value fails

- **WHEN** code calls `Identifier::customer(0)` or `Identifier::customer(-1)`
- **THEN** `\InvalidArgumentException` is thrown (via Webmozart `Assert::positiveInteger`)

#### Scenario: Identifier::registerTransformer wires a consumer-supplied NormalizerBuilder

- **WHEN** a consumer constructs their own `NormalizerBuilder` and calls `Identifier::registerTransformer($builder)`
- **THEN** the returned builder normalizes any `Identifier` instance to `[$fieldName => $value]`
- **AND** the original builder's other configuration (cache, transformers) is preserved

### Requirement: Typed request DTOs cover the `POST /orders/drafts` payload in full

The package SHALL provide a typed request-DTO surface under `Setono\Economic\Request\Order` that covers every field the [`orders.drafts.post.schema.json`](https://restapi.e-conomic.com/schema/orders.drafts.post.schema.json) describes. Required schema fields are non-nullable constructor parameters; optional schema fields default to `null` and are omitted from the serialized JSON via the normalizer's null-skipping transformer.

The DTOs that MUST exist:
- `DraftOrderRequest` — the top-level POST body. Required: `string $date`, `string $currency`, `Identifier $layout`, `Identifier $paymentTerms`, `Identifier $customer`, `Recipient $recipient`. Optional (default `null`): `?float $exchangeRate`, `?string $dueDate`, `?Identifier $project`, `?Identifier $deliveryLocation`, `?Delivery $delivery`, `?Notes $notes`, `?References $references`, `?list<Line> $lines`.
- `Recipient` — `string $name` + `Identifier $vatZone` required; optional address fields per the schema.
- `Delivery` — optional fields for the delivery block.
- `Notes` — heading and text line fields.
- `References` — the order's metadata block (`?Identifier $salesPerson`, `?Identifier $customerContact`, `?Identifier $vendorReference`, `?string $other`).
- `Line` — `string $description`, optional `?float $quantity`, `?float $unitNetPrice`, `?float $discountPercentage`, `?Identifier $product`, `?Identifier $unit`.

Each DTO MUST be `final readonly` and MUST implement `Setono\Economic\Request\Payload` (an empty marker interface used by the normalizer's null-skipping transformer to identify request DTOs). String fields representing required identity (`$date`, `$currency`, `$recipient->name`, `$line->description`) MUST be asserted non-empty in the constructor (`Webmozart\Assert\Assert::notEmpty`). The `currency` field MUST additionally be asserted exactly three characters long (`Assert::length($currency, 3)`).

#### Scenario: Required-only construction succeeds

- **WHEN** code constructs `new DraftOrderRequest(date: '2026-05-27', currency: 'DKK', layout: Identifier::layout(17), paymentTerms: Identifier::paymentTerms(1), customer: Identifier::customer(1), recipient: new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)))`
- **THEN** the DTO is constructed successfully with every optional field at `null`

#### Scenario: Empty required string fails

- **WHEN** code constructs `new DraftOrderRequest(date: '', currency: 'DKK', …)`
- **THEN** `\InvalidArgumentException` is thrown (via Webmozart `Assert::notEmpty`)

#### Scenario: Wrong-length currency fails

- **WHEN** code constructs `new DraftOrderRequest(date: '2026-05-27', currency: 'DKr', …)` with a 3-char value (or `'EUR '` with a trailing space → 4 chars)
- **THEN** the 3-char value succeeds; the 4-char value fails with `\InvalidArgumentException`

#### Scenario: Optional fields default to null and are omitted from serialized JSON

- **WHEN** a `DraftOrderRequest` is constructed with no optional fields
- **AND** `$client->orders()->drafts()->create($request)` is called
- **THEN** the JSON body sent to the server does NOT contain keys for `exchangeRate`, `dueDate`, `project`, `deliveryLocation`, `delivery`, `notes`, `references`, or `lines`

#### Scenario: Optional fields, when supplied, appear in the JSON

- **WHEN** a `DraftOrderRequest` is constructed with `notes: new Notes(heading: 'Hello')` and the rest of the optional fields at `null`
- **THEN** the produced JSON contains a `notes` key with `{"heading": "Hello"}` (and any of `Notes`'s own optional fields are omitted)
- **AND** still no key for any other optional `DraftOrderRequest` field
