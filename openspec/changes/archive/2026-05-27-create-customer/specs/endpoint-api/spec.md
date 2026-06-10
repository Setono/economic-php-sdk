## MODIFIED Requirements

### Requirement: Top-level endpoint accessors are lazy and idempotent

The `Client` SHALL expose one accessor per resource — `products()`, `orders()`, `invoices()`, `customers()`, `self()` — that returns the same endpoint instance on every call within a single `Client` lifetime. These accessors MUST NOT trigger HTTP requests.

#### Scenario: Same endpoint instance returned

- **WHEN** the consumer calls `$client->products()` twice
- **THEN** both calls return the same object (identity)
- **AND** no HTTP request is made

#### Scenario: customers() accessor also memoizes

- **WHEN** the consumer calls `$client->customers()` twice
- **THEN** both calls return the same `CustomersEndpoint` object (identity)
- **AND** no HTTP request is made

### Requirement: Leaf collection sub-endpoints expose `getPage` / `getByNumber` / `paginate`

Every leaf collection sub-endpoint (`ProductsEndpoint`, `DraftOrdersEndpoint`, `SentOrdersEndpoint`, `BookedInvoicesEndpoint`, `CustomersEndpoint`) SHALL extend `CollectionEndpoint<T>` and expose exactly three public methods:

- `getPage(?CollectionRequestOptions $opts = null): Collection<T>` — fetch a single page; the typed `Collection<T>` carries `$raw`.
- `getByNumber(int|string $number): ?T` — fetch one item by its natural identifier; returns `null` on 404; other 4xx propagate as typed exceptions.
- `paginate(?CollectionRequestOptions $opts = null): \Generator<T>` — walk all pages via `pagination.nextPage.url`; yield each item once.

#### Scenario: getPage returns one typed page

- **WHEN** the consumer calls `$client->products()->getPage()` with no arguments
- **THEN** a `Collection<Product>` is returned reflecting the first page with the default page size

#### Scenario: getPage applies request options

- **WHEN** the consumer calls `$client->products()->getPage(new CollectionRequestOptions(pageSize: 50, filter: 'name$like:b'))`
- **THEN** the outgoing request carries the matching `pagesize` and `filter` query parameters

#### Scenario: getByNumber successful lookup

- **WHEN** the consumer calls `$client->products()->getByNumber('123')`
- **AND** the API returns 200 with the product JSON
- **THEN** a `Product` DTO is returned populated from the JSON

#### Scenario: getByNumber returns null on 404

- **WHEN** the consumer calls `$client->products()->getByNumber('does-not-exist')`
- **AND** the API returns 404
- **THEN** `null` is returned (no exception propagates from the SDK)

#### Scenario: getByNumber propagates non-404 client errors

- **WHEN** the consumer calls a `getByNumber` method
- **AND** the API returns a non-404 4xx (e.g. 401, 403)
- **THEN** the appropriate typed exception is thrown (see `error-handling` spec)

#### Scenario: paginate walks all pages

- **WHEN** the consumer iterates over `$client->orders()->sent()->paginate()`
- **AND** the API returns three pages of orders
- **THEN** every order across all three pages is yielded exactly once, in the server-provided order

#### Scenario: paginate stops when nextPage is null

- **WHEN** the walker fetches a page whose `pagination.nextPage` is `null`
- **THEN** all items from that page are yielded
- **AND** the generator returns (no further requests)

#### Scenario: paginate over a single page

- **WHEN** the consumer iterates over `$client->products()->paginate()`
- **AND** the API returns a single page (`nextPage` already `null`)
- **THEN** all items from that page are yielded and the generator returns after exactly one request

#### Scenario: paginate over an empty result

- **WHEN** the consumer iterates over `$client->products()->paginate()`
- **AND** the API returns an empty collection on the first page
- **THEN** the generator yields nothing and returns immediately

#### Scenario: paginate applies request options on the first page

- **WHEN** the consumer iterates over `$client->products()->paginate(new CollectionRequestOptions(pageSize: 100, filter: 'name$like:b'))`
- **THEN** the first request carries `pagesize=100` and the matching `filter`
- **AND** subsequent page requests follow `nextPage.url` (which already encodes the options)

### Requirement: `ResourceEndpoint<T>` declares the resource path + item class

The package SHALL provide an abstract `ResourceEndpoint<T of Resource> extends Endpoint` that declares two abstract hints. Both are `static` because their return values are class-level constants:

- `abstract protected static function getPath(): string;` — the resource path (e.g. `"products"`, `"orders/drafts"`, `"customers"`, `"self"`).
- `abstract protected static function getItemClass(): class-string<T>;` — the FQCN of the typed item DTO.

`ResourceEndpoint` also provides two shared helpers — one for reads, one for writes — both symmetric in shape (HTTP dispatch via `Client::get`/`Client::post`, decode via Valinor with `static::getItemClass()`, stamp `$raw`, return typed `T`):

- `protected function getOne(int|string|null $id = null): T` — issues the GET. When `$id` is null the fetch URL is `getPath()`; when `$id` is given the URL is `"{getPath()}/{$id}"`.
- `protected function createOne(Payload $request): T` — issues the POST to `getPath()` with the given typed request DTO as the body.

Both helpers are `protected` and `@internal` — they exist for the SDK's own leaf endpoints, not as a downstream-subclassing extension point.

#### Scenario: getOne is inherited by both Collection and Self endpoints

- **WHEN** the source of `CollectionEndpoint` and `SelfEndpoint` is inspected
- **THEN** neither class redeclares `getOne`; both inherit it from `ResourceEndpoint`

#### Scenario: createOne is inherited by leaf endpoints that expose create()

- **WHEN** the source of `DraftOrdersEndpoint::create()` and `CustomersEndpoint::create()` is inspected
- **THEN** each leaf's `create()` body is a one-line delegate to `$this->createOne($request)` (with the appropriate typed DTO parameter and return type)
- **AND** neither leaf re-implements the POST + map + stamp pipeline inline

### Requirement: `DraftOrdersEndpoint` exposes `create(DraftOrderRequest): Order`

In addition to the inherited collection methods, `DraftOrdersEndpoint` SHALL expose `create(DraftOrderRequest $request): Order`. The method body MUST be a one-line delegate to the shared helper: `return $this->createOne($request);`. The 404-to-null shortcut used by `getByNumber()` MUST NOT apply: any non-2xx response propagates as the appropriate typed exception (`ValidationException` for 400/422, etc.).

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
- `Identifier::customerGroup(int): self` → `customerGroupNumber`
- `Identifier::vatZone(int): self` → `vatZoneNumber`
- `Identifier::project(int): self` → `projectNumber`
- `Identifier::deliveryLocation(int): self` → `deliveryLocationNumber`
- `Identifier::product(string): self` → `productNumber` (the schema's `productNumber` is a string, max 25 chars — unlike every other identifier, which is an `int`)
- `Identifier::unit(int): self` → `unitNumber`
- `Identifier::employee(int): self` → `employeeNumber`
- `Identifier::customerContact(int): self` → `customerContactNumber`
- `Identifier::vendor(int): self` → `vendorNumber`
- `Identifier::departmentalDistribution(int): self` → `departmentalDistributionNumber`

Each factory MUST validate its argument: the 12 integer factories assert the argument is a positive integer (`Webmozart\Assert\Assert::positiveInteger`); the `product` factory asserts its string argument is non-empty (`Webmozart\Assert\Assert::notEmpty`).

#### Scenario: Each factory pins a `<x>Number` field name

- **WHEN** code calls one of `Identifier::layout(17)`, `Identifier::customer(1)`, `Identifier::customerGroup(2)`, …
- **THEN** the returned instance carries `fieldName === '<x>Number'` matching the factory name and `value === 17` (or `1`, `2`, etc.)

#### Scenario: Construction with non-positive value fails

- **WHEN** code calls `Identifier::customerGroup(0)` or `Identifier::customer(-1)`
- **THEN** `\InvalidArgumentException` is thrown (via Webmozart `Assert::positiveInteger`)

#### Scenario: Identifier::registerTransformer wires a consumer-supplied NormalizerBuilder

- **WHEN** a consumer constructs their own `NormalizerBuilder` and calls `Identifier::registerTransformer($builder)`
- **THEN** the returned builder normalizes any `Identifier` instance to `[$fieldName => $value]`
- **AND** the original builder's other configuration (cache, transformers) is preserved

## ADDED Requirements

### Requirement: `CustomersEndpoint` exposes `create(CustomerRequest): Customer`

In addition to the inherited collection methods, `CustomersEndpoint` SHALL expose `create(CustomerRequest $request): Customer`. The method body MUST be a one-line delegate to the shared helper: `return $this->createOne($request);`. The 404-to-null shortcut used by `getByNumber()` MUST NOT apply: any non-2xx response propagates as the appropriate typed exception (`ValidationException` for 400/422, etc.).

#### Scenario: Successful creation returns a typed Customer

- **WHEN** the consumer calls `$client->customers()->create($request)` with a valid `CustomerRequest`
- **AND** the API returns 201 with the created customer's JSON
- **THEN** a `Customer` instance is returned, populated from the response
- **AND** `Customer::$raw` is the full decoded response body

#### Scenario: Server-side validation failure surfaces as ValidationException

- **WHEN** the API returns 400 or 422 with an e-conomic validation document
- **THEN** `ValidationException` is thrown
- **AND** `$e->getValidationErrors()` returns e-conomic's nested validation document verbatim

#### Scenario: create() goes through Client::post

- **WHEN** `CustomersEndpoint::create($request)` is called
- **THEN** exactly one HTTP request is dispatched
- **AND** that request's method is `POST` and URI is `https://restapi.e-conomic.com/customers`
- **AND** the request body is the JSON normalized form of `$request`

### Requirement: `CustomersEndpoint` is a leaf collection endpoint

`CustomersEndpoint extends CollectionEndpoint<Customer>` SHALL declare `getPath()` returning `"customers"` and `getItemClass()` returning `Customer::class`. It SHALL expose `getByNumber(int $number): ?Customer` as a one-line delegate (`return $this->getItem($number);`) and inherits `getPage()` / `paginate()` unchanged from `CollectionEndpoint`. `CustomersEndpoint` is reached from the `Client` via the new top-level accessor `Client::customers()` (no dispatcher layer — customers have no state-keyed sub-resources like orders' drafts/sent split).

#### Scenario: getByNumber successful lookup

- **WHEN** the consumer calls `$client->customers()->getByNumber(1)`
- **AND** the API returns 200 with the customer JSON
- **THEN** a `Customer` DTO is returned populated from the JSON

#### Scenario: getByNumber returns null on 404

- **WHEN** the consumer calls `$client->customers()->getByNumber(999_999_999)`
- **AND** the API returns 404
- **THEN** `null` is returned

#### Scenario: paginate walks customer pages

- **WHEN** the consumer iterates `$client->customers()->paginate()`
- **AND** the API returns two pages of customers
- **THEN** every customer across both pages is yielded once

### Requirement: `Customer` response DTO types the common scalar fields

The package SHALL provide `Setono\Economic\Response\Customer\Customer extends Resource` typing the following nullable scalar fields:

- Identity / metadata: `?int $customerNumber`, `?string $name`, `?string $currency`, `?bool $barred`, `?string $lastUpdated`.
- Contact & address: `?string $email`, `?string $address`, `?string $zip`, `?string $city`, `?string $country`, `?string $corporateIdentificationNumber`, `?string $vatNumber`.
- Financial state (server-computed): `?float $balance`, `?float $dueAmount`, `?float $creditLimit`.

Every field MUST be `public readonly` with a `null` default (matching the `Order`/`Product` minimal-typing convention). Reference objects (`customerGroup`, `vatZone`, `paymentTerms`, `layout`, `salesPerson`, etc.) and HATEOAS link blobs (`contacts`, `deliveryLocations`, `invoices`, `templates`, `totals`) MUST NOT be typed — they are accessible via `$customer->raw['<key>']`. Niche optional scalars (`pNumber`, `ean`, `publicEntryNumber`, `telephoneAndFaxNumber`, `mobilePhone`, `website`, `eInvoicingDisabledByDefault`) MUST NOT be typed — accessible via `$raw`.

#### Scenario: Typed scalars are populated from the response

- **WHEN** `$client->customers()->getByNumber(1)` returns a customer with `name`, `currency`, `balance`, and `corporateIdentificationNumber` in the response body
- **THEN** the returned `Customer`'s typed properties match those values
- **AND** `Customer::$raw` contains the full decoded response (including the un-typed reference objects)

#### Scenario: Missing optional fields default to null

- **WHEN** the API returns a customer JSON that omits (e.g.) `balance` or `creditLimit`
- **THEN** the returned `Customer`'s `$balance` and `$creditLimit` are `null` (no decoding error)

#### Scenario: Reference objects are not typed; consumers reach them via $raw

- **WHEN** the consumer needs the customer's `customerGroupNumber`
- **THEN** they read `$customer->raw['customerGroup']['customerGroupNumber']`
- **AND** no typed `customerGroup` property exists on the `Customer` class

### Requirement: `CustomerRequest` covers every writable field of the customers POST schema

The package SHALL provide `Setono\Economic\Request\Customer\CustomerRequest` as a `final readonly class implements Payload`. Required schema fields are non-nullable constructor parameters; optional schema fields default to `null` (and are omitted from the serialized JSON via the `Payload` null-skipping transformer). The DTO is flat — no nested DTOs.

Required fields (constructor argument order is design-time-stable):
- `string $name` — asserted non-empty.
- `string $currency` — asserted non-empty AND exactly 3 characters.
- `Identifier $customerGroup` — from `Identifier::customerGroup(int)`.
- `Identifier $vatZone` — from `Identifier::vatZone(int)`.
- `Identifier $paymentTerms` — from `Identifier::paymentTerms(int)`.

Optional fields (every one defaults to `null`):
- `?int $customerNumber` (server-assigned when null), `?bool $barred`, `?string $address`, `?string $city`, `?string $country`, `?string $zip`, `?string $corporateIdentificationNumber`, `?string $pNumber`, `?float $creditLimit`, `?string $ean`, `?string $email`, `?Identifier $layout`, `?string $publicEntryNumber`, `?string $telephoneAndFaxNumber`, `?string $mobilePhone`, `?bool $eInvoicingDisabledByDefault`, `?string $vatNumber`, `?string $website`, `?Identifier $salesPerson` (from `Identifier::employee(int)`).

The `priceGroup` field present in the schema MUST NOT be exposed on `CustomerRequest`. The schema describes it as `{ self: string(uri) }` with no `<x>Number` field, breaking the universal identifier convention. Consumers needing to set `priceGroup` use `Client::post('customers', $rawArrayObject)` directly. This omission MUST be documented in the README's "Creating a customer" section.

#### Scenario: Required-only construction succeeds

- **WHEN** code constructs `new CustomerRequest(name: 'Acme', currency: 'DKK', customerGroup: Identifier::customerGroup(1), vatZone: Identifier::vatZone(1), paymentTerms: Identifier::paymentTerms(1))`
- **THEN** the DTO is constructed successfully with every optional field at `null`

#### Scenario: Empty name is rejected

- **WHEN** code constructs `new CustomerRequest(name: '', ...)`
- **THEN** `\InvalidArgumentException` is thrown (via Webmozart `Assert::notEmpty`)

#### Scenario: Wrong-length currency is rejected

- **WHEN** code constructs `new CustomerRequest(currency: 'EUR ', ...)` (4 chars) or `new CustomerRequest(currency: 'DK', ...)` (2 chars)
- **THEN** `\InvalidArgumentException` is thrown (via Webmozart `Assert::length($currency, 3)`)

#### Scenario: Optional fields default to null and are omitted from serialized JSON

- **WHEN** a `CustomerRequest` is constructed with only its 5 required fields
- **AND** `$client->customers()->create($request)` is called
- **THEN** the JSON body sent to the server contains exactly the 5 required keys (`name`, `currency`, `customerGroup`, `vatZone`, `paymentTerms`)
- **AND** no key for any optional field appears in the body

#### Scenario: Supplied optional fields appear in the JSON

- **WHEN** a `CustomerRequest` is constructed with `email: 'foo@example.com'`, `address: 'Main 1'`, and `layout: Identifier::layout(17)`
- **THEN** the produced JSON contains `email`, `address`, and `layout` (with `{"layoutNumber":17}`)
- **AND** still no key for any other optional field

#### Scenario: priceGroup is not exposed on the DTO

- **WHEN** PHPStan or reflection inspects `CustomerRequest`
- **THEN** there is no `priceGroup` property on the class
- **AND** consumers needing to set `priceGroup` are documented as using `Client::post('customers', $body)` directly
