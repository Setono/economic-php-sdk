# endpoint-api Specification

## Purpose

Defines the public endpoint surface of the SDK: how consumers reach resources (`products()`, `orders()`, `invoices()`, `self()`), how dispatcher endpoints expose state-keyed sub-endpoints, the abstract `ResourceEndpoint` / `CollectionEndpoint` machinery for shared GET / pagination / by-id-lookup behavior, the immutable `CollectionRequestOptions` request DTO, and the shape of entry-point Response DTOs (including the `$raw` escape hatch).

## Requirements

### Requirement: Top-level endpoint accessors are lazy and idempotent

The `Client` SHALL expose one accessor per resource — `products()`, `orders()`, `invoices()`, `self()` — that returns the same endpoint instance on every call within a single `Client` lifetime. These accessors MUST NOT trigger HTTP requests.

#### Scenario: Same endpoint instance returned

- **WHEN** the consumer calls `$client->products()` twice
- **THEN** both calls return the same object (identity)
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

Every leaf collection sub-endpoint (`ProductsEndpoint`, `DraftOrdersEndpoint`, `SentOrdersEndpoint`, `BookedInvoicesEndpoint`) SHALL extend `CollectionEndpoint<T>` and expose exactly three public methods:

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

- `abstract protected static function getPath(): string;` — the resource path (e.g. `"products"`, `"orders/drafts"`, `"self"`).
- `abstract protected static function getItemClass(): class-string<T>;` — the FQCN of the typed item DTO.

`ResourceEndpoint` also provides a shared `protected function getOne(int|string|null $id = null): T` helper that issues the GET, decodes the JSON via `Client::get`, maps via Valinor with `static::getItemClass()`, stamps `$raw`, and returns the typed DTO. When `$id` is null the fetch URL is `getPath()`; when `$id` is given the URL is `"{getPath()}/{$id}"`.

#### Scenario: getOne is inherited by both Collection and Self endpoints

- **WHEN** the source of `CollectionEndpoint` and `SelfEndpoint` is inspected
- **THEN** neither class redeclares `getOne`; both inherit it from `ResourceEndpoint`

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

`CollectionRequestOptions` SHALL be a `final readonly` class with constructor-promoted fields `skipPages` (int, default 0), `pageSize` (int, default 20), `filter` (?string, default null), and `sortBy` (?string, default null). It MUST provide `withX(...)` builders that return a new instance, and a `toArray(): array<string, scalar|null>` method that serializes the options into query parameters. `pageSize` MUST be validated `>= 1 AND <= 1000` (the e-conomic server maximum).

#### Scenario: Defaults

- **WHEN** the consumer constructs `new CollectionRequestOptions()`
- **THEN** `skipPages` is 0, `pageSize` is 20, `filter` is null, `sortBy` is null

#### Scenario: toArray produces the wire format

- **WHEN** the consumer calls `(new CollectionRequestOptions(0, 20, 'name$like:b', 'name'))->toArray()`
- **THEN** the result is `['skippages' => 0, 'pagesize' => 20, 'filter' => 'name$like:b', 'sort' => 'name']`

#### Scenario: Invalid values rejected

- **WHEN** the consumer constructs `new CollectionRequestOptions(skipPages: -1)`
- **THEN** an `\InvalidArgumentException` is thrown

- **WHEN** the consumer constructs `new CollectionRequestOptions(pageSize: 0)`
- **THEN** an `\InvalidArgumentException` is thrown

- **WHEN** the consumer constructs `new CollectionRequestOptions(pageSize: 1001)`
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
