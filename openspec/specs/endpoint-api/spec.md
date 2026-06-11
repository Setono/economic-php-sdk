# endpoint-api Specification

## Purpose

Defines the public endpoint surface of the SDK: how consumers reach resources (`products()`, `orders()`, `invoices()`, `self()`), how dispatcher endpoints expose state-keyed sub-endpoints, the abstract `ResourceEndpoint` / `CollectionEndpoint` machinery for shared GET / pagination / by-id-lookup behavior, the immutable `CollectionRequestOptions` request DTO, and the shape of entry-point Response DTOs (including the `$raw` escape hatch).

## Requirements

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

### Requirement: Dispatcher endpoints expose lazy sub-endpoint accessors

Dispatcher endpoints (`OrdersEndpoint`, `InvoicesEndpoint`) SHALL expose one accessor per state-keyed sub-resource that returns the same sub-endpoint instance on every call:

- `OrdersEndpoint::drafts(): DraftOrdersEndpoint`
- `OrdersEndpoint::sent(): SentOrdersEndpoint`
- `InvoicesEndpoint::booked(): BookedInvoicesEndpoint`

Sub-endpoint accessors MUST NOT trigger HTTP requests. Dispatcher endpoints themselves MUST NOT expose collection methods (`getPage`, `getByNumber`, `paginate`) directly — those live only on leaf sub-endpoints.

#### Scenario: Same sub-endpoint instance returned

- **WHEN** the consumer calls `$client->orders()->drafts()` twice
- **THEN** both calls return the same `DraftOrdersEndpoint` object (identity)
- **AND** no HTTP request is made

#### Scenario: Dispatcher has no collection methods

- **WHEN** the public surface of `OrdersEndpoint` is inspected via reflection
- **THEN** it does not declare `getPage`, `getByNumber`, or `paginate`

### Requirement: Leaf collection sub-endpoints expose `getPage` / `getByNumber` / `paginate`

Every leaf collection sub-endpoint (`ProductsEndpoint`, `DraftOrdersEndpoint`, `SentOrdersEndpoint`, `BookedInvoicesEndpoint`, `CustomersEndpoint`) SHALL extend `CollectionEndpoint<T>` and expose exactly three public methods:

- `getPage(?CollectionRequestOptions $opts = null): Collection<T>` — fetch a single page; the typed `Collection<T>` carries `$raw`.
- `getByNumber(int|string $number): ?T` — fetch one item by its natural identifier; returns `null` on 404; other 4xx propagate as typed exceptions.
- `paginate(?CollectionRequestOptions $opts = null): \Generator<T>` — walk all pages via `pagination.nextPage.url`; yield each item once.

#### Scenario: getPage returns one typed page

- **WHEN** the consumer calls `$client->products()->getPage()` with no arguments
- **THEN** a `Collection<Product>` is returned reflecting the first page with the default page size

#### Scenario: getPage applies request options

- **WHEN** the consumer calls `$client->products()->getPage(new CollectionRequestOptions(pageSize: 50, filter: Filter::like('name', 'b')))`
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

- **WHEN** the consumer iterates over `$client->products()->paginate(new CollectionRequestOptions(pageSize: 100, filter: Filter::like('name', 'b')))`
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

### Requirement: `CollectionEndpoint<T>` adds pagination and by-id lookup on top of `ResourceEndpoint<T>`

The package SHALL provide an abstract `CollectionEndpoint<T of Resource> extends ResourceEndpoint<T>` that adds three methods (no new abstract hints):

- `protected function getItem(int|string $id): ?T;` — calls `getOne($id)`; returns `null` if the underlying call throws `NotFoundException`; other HTTP errors propagate. Leaf sub-endpoints' `getByNumber(...)` methods MUST be one-line delegates to `getItem`. The method MUST be marked `@internal` — it is an implementation helper for the SDK's own leaves and is not covered by the package's backwards-compatibility promise.
- `public function getPage(?CollectionRequestOptions $opts = null): Collection<T>;` — fetches `getPath()` with the given options.
- `public function paginate(?CollectionRequestOptions $opts = null): \Generator<T>;` — walks all pages by following `pagination.nextPage.url`.

Leaf sub-endpoints MUST NOT reimplement `getPage` or `paginate`. They implement `getPath()`, `getItemClass()`, and their own resource-specific lookup methods (e.g. `getByNumber`).

#### Scenario: Pagination machinery is centralized

- **WHEN** the source for any leaf collection sub-endpoint is inspected
- **THEN** `getPage` and `paginate` are inherited from `CollectionEndpoint` (neither is declared on the leaf)

#### Scenario: Leaf getByNumber is a one-liner

- **WHEN** the source for any leaf's `getByNumber` is inspected
- **THEN** its body is exactly `return $this->getItem($number);` (with the appropriate id type)

### Requirement: `SelfEndpoint` extends `ResourceEndpoint`, not `CollectionEndpoint`

`SelfEndpoint extends ResourceEndpoint<Self_>` SHALL declare `getPath()` returning `"self"` and `getItemClass()` returning `Self_::class`. Its `get(): Self_` method MUST call `$this->getOne()` (no id) and memoize the result inside a private `?Self_ $cached` field. It MUST NOT extend `CollectionEndpoint` — there is no pagination or by-id lookup for `/self`.

#### Scenario: SelfEndpoint uses getOne for the actual GET

- **WHEN** the source of `SelfEndpoint::get()` is inspected
- **THEN** it calls `$this->getOne()` rather than re-implementing the fetch-map-stamp pipeline

### Requirement: `Collection<T>` is a passive data carrier

`Collection<T>` SHALL hold `collection: list<T>`, `pagination: Pagination`, and `raw: array<string, mixed>` (set by the endpoint after Valinor mapping). It MUST NOT carry a fetcher closure, MUST NOT expose `paginate()`, MUST NOT expose `setFetcher()`. Pagination logic lives on `CollectionEndpoint<T>`, not on `Collection<T>`.

#### Scenario: Collection has no paginate method

- **WHEN** the public surface of `Collection<T>` is inspected via reflection
- **THEN** it does not declare `paginate`, `setFetcher`, or any property named `$fetcher`

### Requirement: Collection request options are immutable

`CollectionRequestOptions` SHALL be a `final readonly` class with constructor-promoted fields `skipPages` (int, default 0), `pageSize` (int, default 20), `filter` (?Filter, default null), and `sortBy` (?string, default null). It MUST provide `withX(...)` builders that return a new instance, and a `toArray(): array<string, scalar|null>` method that serializes the options into query parameters — the `filter` entry being the rendered (unencoded) filter expression. `pageSize` MUST be validated `>= 1 AND <= 1000` (the e-conomic server maximum). Raw filter strings are NOT accepted; hand-written expressions enter through `Filter::raw()`.

#### Scenario: Defaults

- **WHEN** the consumer constructs `new CollectionRequestOptions()`
- **THEN** `skipPages` is 0, `pageSize` is 20, `filter` is null, `sortBy` is null

#### Scenario: toArray produces the wire format

- **WHEN** the consumer calls `(new CollectionRequestOptions(0, 20, Filter::like('name', 'b'), 'name'))->toArray()`
- **THEN** the result is `['skippages' => 0, 'pagesize' => 20, 'filter' => 'name$like:b', 'sort' => 'name']`

#### Scenario: Invalid values rejected

- **WHEN** the consumer constructs `new CollectionRequestOptions(skipPages: -1)`
- **THEN** an `\InvalidArgumentException` is thrown

- **WHEN** the consumer constructs `new CollectionRequestOptions(pageSize: 0)`
- **THEN** an `\InvalidArgumentException` is thrown

- **WHEN** the consumer constructs `new CollectionRequestOptions(pageSize: 1001)`
- **THEN** an `\InvalidArgumentException` is thrown

### Requirement: Typed filter builder

`Setono\Economic\Request\Filter` SHALL be a `final readonly class` implementing `\Stringable` with a private constructor and one named static factory per e-conomic filter operator: `eq`, `ne`, `gt`, `gte`, `lt`, `lte` (value type `string|int|float|bool|\DateTimeInterface|null`), `like` (string value), and `in` / `nin` (`list<int|string|null>`, non-empty, max 200 elements per the e-conomic cap). Filters MUST combine via instance methods `and(self $other, self ...$others)` / `or(self $other, self ...$others)`, and `__toString()` MUST return the unencoded e-conomic filter expression (URL encoding is the `Client`'s responsibility).

Value rendering rules:
- special characters in string values (`$ ( ) * , [ ]`) are `$`-escaped per e-conomic's escape table; in `like` values the `*` wildcard is preserved
- `null` renders as the `$null:` sentinel (also as an `in`/`nin` list element)
- `\DateTimeInterface` is converted to UTC (without mutating the input) and formatted `Y-m-d\TH:i:s\Z`; date-only fields take pre-formatted `Y-m-d` strings
- `bool` renders as `true`/`false`; non-finite floats are rejected

Because e-conomic does not document `$and:`/`$or:` precedence, any composite operand (including the receiver) MUST be parenthesized when combined. `Filter::raw(string)` SHALL wrap a hand-written expression verbatim (no escaping or validation) as the escape hatch for anything the factories cannot express; a raw filter is treated as composite when combined.

#### Scenario: Comparison rendering

- **WHEN** the consumer builds `Filter::gte('lastUpdated', new \DateTimeImmutable('2026-01-01 01:30:00', new \DateTimeZone('Europe/Copenhagen')))`
- **THEN** `(string) $filter` is `lastUpdated$gte:2026-01-01T00:30:00Z`

#### Scenario: Values are escaped

- **WHEN** the consumer builds `Filter::eq('name', 'a$b(c)*d,e[f]')`
- **THEN** `(string) $filter` is `name$eq:a$$b$(c$)$*d$,e$[f$]`

#### Scenario: Composites are parenthesized

- **WHEN** the consumer builds `Filter::eq('name', 'Joe')->and(Filter::like('city', '*port')->or(Filter::lt('age', 40)))`
- **THEN** `(string) $filter` is `name$eq:Joe$and:(city$like:*port$or:age$lt:40)`

#### Scenario: List constraints enforced

- **WHEN** the consumer builds `Filter::in('customerNumber', [])` or passes more than 200 elements
- **THEN** an `\InvalidArgumentException` is thrown

### Requirement: `Query` class is removed

The `Setono\Economic\Request\Query` class SHALL NOT exist in v2. `Client::get()` MUST accept `array<string, scalar|null>` directly for its `$query` parameter.

#### Scenario: Query class absent

- **WHEN** a consumer attempts to `use Setono\Economic\Request\Query`
- **THEN** the class is not found (the file does not exist)

### Requirement: Entry-point DTOs expose `$raw`

Each entry-point Response DTO — `Product`, `Order`, `BookedInvoice`, and `Collection<T>` — SHALL expose a public `array $raw` property containing the full decoded JSON for that DTO's slice of the response. Nested DTOs (`Inventory`, `Line`, `Pagination`, `Page`) SHALL NOT carry a `$raw` property; their data is reachable through the parent's `$raw`.

#### Scenario: Raw populated on lookup result

- **WHEN** the consumer calls `$client->products()->getByNumber('5')`
- **AND** the API returns a JSON object with a `costPrice` field that is not in the typed `Product`
- **THEN** the returned `Product` carries `$raw` populated with the full decoded response, and `$product->raw['costPrice']` is accessible

#### Scenario: Raw populated on collection result

- **WHEN** the consumer calls `$client->products()->getPage()`
- **THEN** the returned `Collection<Product>` carries `$raw` populated with the full decoded response envelope (including `collection`, `pagination`, and any extra envelope fields)
- **AND** every item inside `$collection->collection` carries its own `$raw` populated with that item's slice of the response body (so pagination consumers can reach for untyped fields per item, with parity to `getByNumber`)

#### Scenario: Raw populated on each item yielded by paginate

- **WHEN** the consumer iterates `$client->products()->paginate()`
- **THEN** every yielded item carries `$raw` populated with that item's slice of the response body

#### Scenario: Nested DTOs do not carry `$raw`

- **WHEN** the consumer accesses `$product->inventory`
- **THEN** the `Inventory` object has no `$raw` property

### Requirement: Entry-point DTOs use per-property readonly, not class-level readonly

Each entry-point DTO MUST mark its typed fields as `public readonly` individually (so they remain immutable), but the class itself MUST NOT carry the class-level `readonly` modifier (so the endpoint can assign `$raw` post-mapping). The `rector.php` skip list MUST exclude `ReadOnlyClassRector` for these classes.

#### Scenario: Typed field is immutable

- **WHEN** the consumer attempts `$product->name = 'something'`
- **THEN** PHP throws an `Error` (readonly property)

#### Scenario: Raw field is assignable by the endpoint after construction

- **WHEN** the endpoint constructs the DTO via Valinor and then assigns `$dto->raw = $decodedJson`
- **THEN** the assignment succeeds

### Requirement: Property-bag DTOs are migrated to readonly constructor promotion

`Order`, `Line`, and `BookedInvoice` SHALL be migrated from mutable public properties to `public readonly` constructor-promoted fields (per the previous requirement). `Order::lines` MUST default to `[]` so an order without lines does not blow up Valinor mapping.

#### Scenario: Order has readonly fields

- **WHEN** the consumer attempts to assign `$order->orderNumber = 99`
- **THEN** PHP throws an `Error` (readonly property)

#### Scenario: Order without lines

- **WHEN** an order is mapped from JSON that has no `lines` key
- **THEN** the resulting `Order` has `lines` equal to `[]`

### Requirement: No endpoint interfaces

The package MUST NOT export `ProductsEndpointInterface`, `OrdersEndpointInterface`, `InvoicesEndpointInterface`, or the base `EndpointInterface`. Consumers needing to fake an endpoint MUST inject a fake PSR-18 client into the real `Client`.

#### Scenario: Interface files absent

- **WHEN** the repository tree is inspected
- **THEN** no file named `*EndpointInterface.php` exists under `src/Client/Endpoint/`

### Requirement: Generic request escape hatch is preserved for un-typed endpoints

When the SDK does not yet wrap a particular e-conomic endpoint, the consumer MUST be able to use `Client::request()` (defined in `http-transport`) to issue a typed PSR-7 request directly and parse the response themselves.

#### Scenario: Consumer issues an untyped GET

- **WHEN** the consumer builds a PSR-7 GET for an un-wrapped endpoint and passes it to `Client::request()`
- **THEN** the request is dispatched, auth + `User-Agent` are applied, status-code dispatch runs, and the raw PSR-7 response is returned

### Requirement: `Client::self()` returns a `SelfEndpoint`

The `Client` SHALL expose `self(): SelfEndpoint` that returns a lazily-constructed `SelfEndpoint` instance, consistent with the other endpoint accessors (`products()`, `orders()`, `invoices()`). The accessor itself MUST NOT trigger an HTTP request. Calling `self()` twice MUST return the same `SelfEndpoint` instance.

#### Scenario: Same endpoint instance returned

- **WHEN** the consumer calls `$client->self()` twice on the same `Client`
- **THEN** both calls return the same `SelfEndpoint` object (identity)
- **AND** neither call triggers an HTTP request

### Requirement: `SelfEndpoint::get()` fetches and memoizes the DTO

`SelfEndpoint::get(): Self_` SHALL perform `GET /self` on first invocation, map the response into a `Self_` DTO, memoize the DTO inside the endpoint, and return it. Subsequent calls MUST return the memoized DTO without another HTTP request.

#### Scenario: First get() issues the request

- **WHEN** the consumer calls `$client->self()->get()` for the first time
- **THEN** the SDK issues `GET /self`
- **AND** the response is mapped into a `Self_` DTO
- **AND** the DTO is returned

#### Scenario: Subsequent get() calls return the cached DTO

- **WHEN** the consumer calls `$client->self()->get()` twice (on the same `SelfEndpoint`)
- **THEN** only one `GET /self` request is dispatched
- **AND** both calls return the same DTO object (identity)

### Requirement: `Self_` is an entry-point DTO

The `Self_` Response DTO SHALL extend `Resource` and carry `$raw` populated with the full decoded response. Typed fields are populated by Valinor from the JSON body. The class MUST NOT be `final readonly class` (so `$raw` can be assigned after Valinor maps), but its typed fields MUST be `public readonly`. The class name `Self_` uses the standard PHP trailing-underscore convention because `Self` is a reserved word.

#### Scenario: Self_ carries raw

- **WHEN** `$client->self()->get()` is called
- **AND** `GET /self` returns a JSON object containing fields not declared on the `Self_` DTO
- **THEN** the returned `Self_` has `$raw` populated with the full decoded response
- **AND** the undeclared fields are accessible via `$self->raw[...]`

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
