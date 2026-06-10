# Economic PHP SDK

[![Latest Version][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]
[![Mutation testing][ico-infection]][link-infection]

A modern PHP SDK for the [e-conomic REST API](https://restdocs.e-conomic.com/). Typed DTOs for both requests and responses, fully constructor-injected HTTP collaborators (you bring your own PSR-18 client), no surprising magic. Built for PHP 8.4.

**What you get**

- **Typed responses.** `Customer`, `Order`, `Product`, `BookedInvoice`, … come back as concrete DTOs with `public readonly` properties. Your IDE autocompletes; PHPStan at level: max stays happy.
- **Typed write payloads.** `CustomerRequest`, `DraftOrderRequest`, … with constructor-enforced required fields. Optional fields default to `null` and are stripped from the JSON sent to e-conomic — no risk of accidentally sending empty `notes` blocks.
- **`$raw` escape hatch.** Every response DTO carries `public array $raw` with the full decoded JSON, so any field the SDK doesn't type yet is still reachable.
- **Bring your own HTTP client.** PSR-18 + PSR-17 with auto-discovery; inject your own to add retries, logging, or metrics via standard middleware.
- **Honest exceptions.** Server-side validation errors surface as `ValidationException` with e-conomic's nested error document preserved verbatim. 2xx responses that don't fit the SDK's DTO shape surface as `MappingException` with the original Valinor error chained.

## Requirements

- PHP **8.4** or newer.
- A PSR-18 HTTP client (e.g. `nyholm/psr7` + `symfony/http-client`, `guzzlehttp/guzzle` 7+, …). The SDK uses [`php-http/discovery`](https://github.com/php-http/discovery) to find what you have installed; for production you almost certainly want to inject explicitly (see [Bringing your own HTTP client](#bringing-your-own-http-client)).

## Installation

```bash
composer require setono/economic-php-sdk
```

If you don't already have a PSR-18 client in your project, install one alongside the SDK:

```bash
composer require nyholm/psr7 symfony/http-client
```

## Quick start

```php
<?php

use Setono\Economic\Client\Client;
use Setono\Economic\Exception\EconomicException;

require_once 'vendor/autoload.php';

$client = new Client('YOUR_APP_SECRET_TOKEN', 'YOUR_AGREEMENT_GRANT_TOKEN');

try {
    $customer = $client->customers()->getByNumber(5);
    if ($customer === null) {
        echo "Customer 5 not found.\n";
        return;
    }

    echo "Hello, {$customer->name} ({$customer->currency}).\n";
    echo "Balance: {$customer->balance}\n";
} catch (EconomicException $e) {
    // Any non-2xx from e-conomic; auth, validation, server errors.
    // $e->getMessage() includes "[METHOD URL]" (query/fragment stripped).
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
```

The two constructor arguments are your app's [secret token + agreement grant token](https://restdocs.e-conomic.com/#a-headers); see e-conomic's docs for how to obtain them. `'demo'` / `'demo'` works against e-conomic's demo agreement if you just want to try the SDK without onboarding.

## Reading data

### Looking up a single resource

`getByNumber(int|string): ?T` looks up one item by its natural identifier. Returns `null` on 404; other client errors throw the typed exception.

```php
$customer = $client->customers()->getByNumber(5);   // ?Customer
$product  = $client->products()->getByNumber('SKU-001'); // ?Product (product numbers are strings)
$order    = $client->orders()->drafts()->getByNumber(42); // ?Order
$invoice  = $client->invoices()->booked()->getByNumber(9001); // ?BookedInvoice
```

The returned DTO has typed properties for the most-accessed scalars, plus `$raw` for everything else:

```php
$customer->customerNumber;            // ?int
$customer->name;                      // ?string
$customer->email;                     // ?string
$customer->balance;                   // ?float (server-computed)

// Anything not typed lives in $raw:
$customer->raw['customerGroup']['customerGroupNumber'] ?? null;
$customer->raw['salesPerson']['employeeNumber']        ?? null;
```

### Get a single page

```php
use Setono\Economic\Client\Client;
use Setono\Economic\Request\CollectionRequestOptions;

$client = new Client('demo', 'demo');

$page = $client->products()->getPage(
    new CollectionRequestOptions(
        pageSize: 50,
        filter:   'name$like:b',
        sortBy:   'name',
    ),
);

foreach ($page->collection as $product) {
    echo "{$product->productNumber}\t{$product->name}\n";
}

// $page also carries pagination metadata:
$page->pagination->results;             // int — items on this page
$page->pagination->resultsWithoutFilter; // int — items without the filter
$page->pagination->nextPage?->url;       // ?string — null on the last page
```

`CollectionRequestOptions` is immutable; chain `withX()` methods if you want to derive variants:

```php
$opts = new CollectionRequestOptions(pageSize: 50);
$nameSorted = $opts->withSortBy('name');
$nameFiltered = $opts->withFilter('name$like:b');
```

See [e-conomic's filter syntax](https://restdocs.e-conomic.com/#filter-operators) for the full `$like` / `$gt` / `$in:` operator set.

### Paginate (walk all pages)

```php
<?php
use Setono\Economic\Client\Client;
use Setono\Economic\Request\CollectionRequestOptions;

$client = new Client('demo', 'demo');

foreach ($client->products()->paginate() as $product) {
    // ... handle each product, one at a time, memory-flat
}

// With filter / sort:
foreach ($client->products()->paginate(new CollectionRequestOptions(filter: 'name$like:b', sortBy: 'name')) as $product) {
    // ...
}
```

`paginate()` follows the server-provided `pagination.nextPage.url` after the first page — no manual `skipPages` bookkeeping. Available on every collection endpoint:

```php
foreach ($client->customers()->paginate() as $customer)         { /* ... */ }
foreach ($client->orders()->drafts()->paginate() as $order)      { /* ... */ }
foreach ($client->orders()->sent()->paginate() as $order)        { /* ... */ }
foreach ($client->invoices()->booked()->paginate() as $invoice)  { /* ... */ }
```

If page N+1 fetch fails mid-walk, the generator is exhausted; items from page N have already been yielded. Recovering requires either retry middleware on your PSR-18 client (so transient errors don't surface here) or tracking processed IDs externally so a fresh `paginate()` call can dedupe.

### Ping / who am I

The `/self` endpoint returns information about the current API agreement — useful as a smoke test that your tokens work.

```php
$self = $client->self()->get();
// $self->raw contains the full /self response (loggedInUserType, serverTime, agreement details, …)
```

`Client::self()` returns a `SelfEndpoint` (cheap, no HTTP). `SelfEndpoint::get()` performs `GET /self` on first call and memoizes the DTO for subsequent calls — calling it again in the same `Client` lifetime won't hit the network.

## Writing data

### Creating a draft order

Schema: [`orders.drafts.post.schema.json`](https://restapi.e-conomic.com/schema/orders.drafts.post.schema.json).

```php
use Setono\Economic\Client\Client;
use Setono\Economic\Request\Identifier;
use Setono\Economic\Request\Order\DraftOrderRequest;
use Setono\Economic\Request\Order\Line;
use Setono\Economic\Request\Order\Notes;
use Setono\Economic\Request\Order\Recipient;

$client = new Client('API_KEY', 'API_SECRET');

$request = new DraftOrderRequest(
    date:         '2026-05-27',
    currency:     'DKK',
    layout:       Identifier::layout(17),
    paymentTerms: Identifier::paymentTerms(1),
    customer:     Identifier::customer(1),
    recipient:    new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
);

$order = $client->orders()->drafts()->create($request);
$order->orderNumber;             // typed (int)
$order->raw['grossAmount'] ?? 0; // server-computed fields land in $raw
```

Optional fields default to `null` and are omitted from the JSON sent to e-conomic — there is no risk of accidentally sending an empty `notes` block:

```php
$request = new DraftOrderRequest(
    date:         '2026-05-27',
    currency:     'DKK',
    layout:       Identifier::layout(17),
    paymentTerms: Identifier::paymentTerms(1),
    customer:     Identifier::customer(1),
    recipient:    new Recipient(name: 'Foo', vatZone: Identifier::vatZone(1)),
    notes:        new Notes(heading: 'Greetings'),
    lines: [
        new Line(
            description:  'Widget',
            quantity:     2.5,
            unitNetPrice: 49.95,
            product:      Identifier::product('SKU-001'),
        ),
    ],
);
```

`Identifier` is the single foreign-key wrapper for every `{<x>Number: int}` (or `productNumber: string`) reference the schema accepts. Named factories cover every reference type: `Identifier::layout()`, `Identifier::customer()`, `Identifier::customerGroup()`, `Identifier::vatZone()`, `Identifier::project()`, `Identifier::product()`, `Identifier::unit()`, `Identifier::employee()`, `Identifier::customerContact()`, `Identifier::vendor()`, `Identifier::deliveryLocation()`, `Identifier::paymentTerms()`, `Identifier::departmentalDistribution()`.

### Updating a draft order

`update(int $number, DraftOrderRequest $request): Order` PUTs to `orders/drafts/:number`. **e-conomic PUT is full-replace**: any field absent from the body — including every `null` property, which the SDK omits from the JSON — is cleared server-side. Use the same read-modify-write flow as for customers (see below): fetch the order, prefill with `DraftOrderRequest::fromResponse()`, change what you need, PUT the whole thing back:

```php
$order = $client->orders()->drafts()->getByNumber(42);

$request = DraftOrderRequest::fromResponse($order);
$request->notes = new Notes(heading: 'Updated');

$updated = $client->orders()->drafts()->update(42, $request);
```

### Creating a customer

Schema: [`customers.post.schema.json`](https://restapi.e-conomic.com/schema/customers.post.schema.json).

```php
use Setono\Economic\Client\Client;
use Setono\Economic\Request\Customer\CustomerRequest;
use Setono\Economic\Request\Identifier;

$client = new Client('API_KEY', 'API_SECRET');

$request = new CustomerRequest(
    name:          'Acme A/S',
    currency:      'DKK',
    customerGroup: Identifier::customerGroup(1),
    vatZone:       Identifier::vatZone(1),
    paymentTerms:  Identifier::paymentTerms(14),
);

$customer = $client->customers()->create($request);
$customer->customerNumber;                                       // typed (int) — server-assigned
$customer->name;                                                 // typed (string)
$customer->raw['customerGroup']['customerGroupNumber'] ?? null;  // references live in $raw
```

A fuller example with optional fields:

```php
$request = new CustomerRequest(
    name:          'Acme A/S',
    currency:      'DKK',
    customerGroup: Identifier::customerGroup(1),
    vatZone:       Identifier::vatZone(1),
    paymentTerms:  Identifier::paymentTerms(14),
    email:         'invoices@acme.example',
    address:       'Main 1',
    zip:           '2100',
    city:          'Copenhagen',
    country:       'Denmark',
    corporateIdentificationNumber: '12345678',
    layout:        Identifier::layout(17),
    salesPerson:   Identifier::employee(5),
);
```

`$client->customers()` also exposes `getByNumber(int): ?Customer`, `getPage(...)`, and `paginate(...)` — the same surface as the other top-level resources.

**Note on `priceGroup`:** the e-conomic schema describes `priceGroup` as a `{ self: uri }` reference with no `priceGroupNumber` field, breaking the universal `<x>Number` convention. `CustomerRequest` therefore omits it. Consumers needing to set the price group can drop down to the low-level helper: `$client->post('customers', $hand_built_payload)`.

### Updating a customer (read–modify–write)

**e-conomic PUT is full-replace**: any field absent from the body is cleared server-side. Never build an update request with only the fields you want to change — fetch the customer first, prefill a request from it with `CustomerRequest::fromResponse()`, mutate what you need, and PUT the whole thing back:

```php
$customer = $client->customers()->getByNumber(42);

$request = CustomerRequest::fromResponse($customer);
$request->email = 'billing@acme.example';  // change what you need
$request->mobilePhone = null;              // null = omitted from the JSON = cleared server-side

$updated = $client->customers()->update(42, $request);
```

`fromResponse()` is available on every request DTO (it lives on the `Payload` base class): it maps the response's full decoded body (`$raw`) into the request DTO via Valinor. Every field the DTO models is carried over — reference objects like `customerGroup` or `salesPerson` become `Identifier` instances automatically, with the JSON field name inferred from the reference's `<x>Number` key — and everything else (server-computed fields, HATEOAS links, unmodeled schema fields) is dropped. It therefore requires a response fetched through the SDK — on a hand-constructed instance (empty `$raw`) it throws. Raw data that is present but malformed also throws instead of being silently dropped, because a dropped field would be wiped by the subsequent PUT.

**Caveat:** schema fields the SDK doesn't model (`priceGroup`, `customerContact`, `attention`, `defaultDeliveryLocation`, …) cannot be carried over and **will be cleared** by an update built this way. If you use those fields, hand-build the body and dispatch via `Client::request()`.

**Caveat on `eInvoicingDisabledByDefault`:** the e-conomic docs state this property "is updatable only by using PATCH to /customers/:customerNumber" — the API's one exception to its no-PATCH-on-JSON rule. Its value in a PUT body is ignored, so changing it via `update()` has no effect (it is not cleared by omission either). To toggle it, send a JSON Patch request via `Client::request()`.

## Error handling

The SDK uses two distinct error patterns for reads and writes:

**Lookups (`getByNumber`) return `null` on 404 — they do NOT throw.** This makes optional-lookup code clean. Non-404 client errors (auth, validation) still throw the typed exception.

```php
$product = $client->products()->getByNumber('does-not-exist');
if ($product === null) {
    // not found — no exception
}
```

**Writes (`create`, `update`), custom `request()` calls, and `get()` always throw on any non-2xx.** Catch by type:

```php
use Setono\Economic\Exception\EconomicException;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Exception\ValidationException;

try {
    $customer = $client->customers()->create($req);
} catch (ValidationException $e) {
    // e-conomic's structured validation errors:
    $errors = $e->getValidationErrors(); // raw nested document; see e-conomic docs
    $hint   = $e->getDeveloperHint();
    $logId  = $e->getLogId();           // include in support tickets
} catch (EconomicException $e) {
    // marker interface catches every SDK exception
    // $e->getMessage() includes the HTTP method + URL (query/fragment stripped) for debugging
}
```

The exception hierarchy maps HTTP status codes to typed exceptions:

| Status | Exception |
|---|---|
| 400, 422 | `ValidationException` |
| 401 | `UnauthorizedException` |
| 403 | `ForbiddenException` |
| 404 | `NotFoundException` |
| 405 | `MethodNotAllowedException` |
| 500 | `InternalServerErrorException` |
| 501 | `NotImplementedException` |
| anything else (415, 429, 502, 504, …) | `UnexpectedStatusCodeException` |

All extend `ResponseAwareException` and implement `EconomicException` (the marker interface). Lazy-parse getters on every response-aware exception give you the e-conomic error envelope:

- `getErrorCode(): ?int` — e-conomic's numeric error code
- `getDeveloperHint(): ?string` — human-readable hint from e-conomic
- `getLogId(): ?string` — include in support tickets
- `getLogTime(): ?\DateTimeImmutable` — when e-conomic logged the error
- `getValidationErrors(): array` — the nested validation document (only populated on 400/422)

Retry policy belongs in a PSR-18 decorator, not in the SDK. `UnexpectedStatusCodeException` covers transient (429, 502, 504) and permanent (415, …) failures alike — wrap your `httpClient:` with retry middleware if you want automatic recovery (see [Adding retries with Symfony HttpClient](#adding-retries-with-symfony-httpclient)).

A 2xx response whose body doesn't fit the SDK's DTO shape (server schema change, version skew) surfaces as `MappingException extends MalformedResponseException` — same parent as a JSON-decode failure, with the original Valinor `MappingError` preserved as `$previous` for the full type-mismatch tree.

PSR-18 network errors (`Psr\Http\Client\NetworkExceptionInterface`, e.g. connection refused, DNS failure) propagate **unwrapped** — the SDK doesn't catch them. `catch (EconomicException)` does NOT net them; add a separate `catch (\Psr\Http\Client\ClientExceptionInterface)` if you want to handle network and API errors together.

## Testing code that calls the SDK

Inject a fake PSR-18 client via the `httpClient:` named argument. PHP 8.4's anonymous classes make a single-purpose fake compact:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;
use Setono\Economic\Client\Client;

$fake = new class() implements ClientInterface {
    public ?RequestInterface $captured = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->captured = $request;

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"customerNumber":1,"name":"Acme","currency":"DKK"}',
        );
    }
};

$client = new Client('demo', 'demo', httpClient: $fake);
$customer = $client->customers()->getByNumber(1);

// Assert against the captured request:
assert($fake->captured?->getMethod() === 'GET');
assert((string) $fake->captured->getUri() === 'https://restapi.e-conomic.com/customers/1');

// And the typed response:
assert($customer?->customerNumber === 1);
assert($customer->name === 'Acme');
```

For tests that need to script multiple URL → response mappings, the SDK's own test suite uses a small URL-keyed fake at `tests/TestDouble/ScriptedHttpClient.php` (~30 lines). It's not publicly autoloaded, but copy-paste-able.

Constructing response DTOs directly in tests (without going through the SDK or Valinor) is also supported — every entry-point DTO has constructor-promoted readonly properties:

```php
$customer = new \Setono\Economic\Response\Customer\Customer(
    customerNumber: 1,
    name:           'Acme',
    currency:       'DKK',
);
$customer->raw = ['extraField' => 'value']; // $raw is intentionally not readonly
```

## Raw access (when a field isn't typed yet)

Every entry-point DTO (`Product`, `Order`, `BookedInvoice`, `Self_`, `Collection<T>`) carries a `public array $raw` with the full decoded JSON for that response. Nested DTOs don't carry `$raw` — reach them via the parent's `$raw['nested-key']`.

```php
$product = $client->products()->getByNumber('5');
$product->name;                    // typed
$product->raw['costPrice'] ?? null; // any field e-conomic returns
```

When re-serializing a DTO (caching, logging, audit trail), use `$dto->raw` directly — that's the full API response. `json_encode($product)` would produce a hybrid of the typed-readonly fields plus an embedded `raw` key, which is rarely what you want.

```php
file_put_contents($cachePath, json_encode($product->raw, JSON_THROW_ON_ERROR));
```

## Long-running processes

Reuse a single `Client` instance across a batch loop. `Client` construction discovers PSR-18 / PSR-17 implementations eagerly and builds default Valinor `MapperBuilder` / `NormalizerBuilder` instances — paying that cost once per item in a worker / import script is wasteful, and without caching every request also recompiles Valinor's mapping definitions.

```php
// ✗ antipattern — pays discovery + builder construction per item
foreach ($customerImports as $row) {
    $client = new Client($token, $agreement);            // expensive per iteration
    $client->customers()->create($row->toRequest());
}

// ✓ corrected — one Client, cached Valinor builders for batch workloads
$cache  = new \CuyZ\Valinor\Cache\FileSystemCache('/var/cache/economic');
$client = new Client(
    $token, $agreement,
    mapperBuilder:     (new \CuyZ\Valinor\MapperBuilder())->withCache($cache),
    normalizerBuilder: Client::registerNormalizerTransformers(
        (new \CuyZ\Valinor\NormalizerBuilder())->withCache($cache),
    ),
);
foreach ($customerImports as $row) {
    $client->customers()->create($row->toRequest());
}
```

## Other requests (low-level helpers)

If the endpoint or method you want to call isn't present yet, you have two options: 1) create a PR and add the missing parts, or 2) use the SDK's low-level helpers.

For un-wrapped JSON endpoints, `Client::get()` returns the decoded body directly. It accepts either a path relative to the e-conomic base URI or a fully-qualified URL pointing at the e-conomic API:

```php
$client = new Setono\Economic\Client\Client('API_KEY', 'API_SECRET');

$data = $client->get('customers/123');                 // array<string, mixed>
$list = $client->get('customers', ['pagesize' => 10]); // array<string, mixed>
$page = $client->get('https://restapi.e-conomic.com/customers?skippages=2&pagesize=20');
```

Absolute URLs are validated against the SDK's base host — `get()` refuses to send auth credentials to any other host.

For non-JSON endpoints (PDF downloads, attachment files) or for full control of the PSR-7 cycle, build a request and use `Client::request()` — it still returns `ResponseInterface`:

```php
$response = $client->request($request);   // PSR-7 ResponseInterface
```

Auth headers, `User-Agent`, and status-code dispatch all apply to both paths.

## Bringing your own HTTP client

The SDK follows the [PSR-18](https://www.php-fig.org/psr/psr-18/) "bring your own HTTP client" pattern: every collaborator is constructor-injected with sensible defaults. By default it discovers whatever PSR-18 / PSR-17 implementations you already have installed:

```php
<?php

use Setono\Economic\Client\Client;

require_once 'vendor/autoload.php';

// Zero config — discovery finds the PSR-18 client and PSR-17 factories that are installed.
$client = new Client('API_KEY', 'API_SECRET');
```

Inject your own client when you need control over the transport.

### Adding retries with Symfony HttpClient

`RetryableHttpClient` wraps any Symfony HttpClient and re-fires the request with exponential backoff on transient failures (network errors, 5xx, 429). The SDK sees the retried response as the canonical one.

```php
<?php

use Setono\Economic\Client\Client;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

$transport = new RetryableHttpClient(
    HttpClient::create(),
    new GenericRetryStrategy(
        // retry on these status codes, with default exponential backoff:
        statusCodes: [423, 425, 429, 500, 502, 503, 504, 507, 510],
        delayMs:     1_000,
        multiplier:  2.0,
        maxDelayMs:  10_000,
    ),
    maxRetries: 3,
);

$client = new Client('API_KEY', 'API_SECRET', httpClient: new Psr18Client($transport));
```

429 responses still surface as `UnexpectedStatusCodeException` if all retries are exhausted — the SDK doesn't have a `RateLimitException`. Inspect `$e->getResponse()->getHeaderLine('Retry-After')` if you want to back off further at the application layer.

### Logging requests

There is no built-in logger. Wrap your PSR-18 client to log — any PSR-18-compatible middleware works, since the SDK never reaches around the injected client.

```php
<?php

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Setono\Economic\Client\Client;

final readonly class LoggingHttpClient implements ClientInterface
{
    public function __construct(
        private ClientInterface $inner,
        private LoggerInterface $logger,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $start = microtime(true);
        try {
            $response = $this->inner->sendRequest($request);
            $this->logger->info('economic API call', [
                'method'      => $request->getMethod(),
                'uri'         => (string) $request->getUri()->withQuery('')->withFragment(''),
                'status'      => $response->getStatusCode(),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('economic API call failed', [
                'method'      => $request->getMethod(),
                'uri'         => (string) $request->getUri()->withQuery('')->withFragment(''),
                'exception'   => $e::class,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
            throw $e;
        }
    }
}

$psr18 = new \Symfony\Component\HttpClient\Psr18Client();
$client = new Client('API_KEY', 'API_SECRET', httpClient: new LoggingHttpClient($psr18, $logger));
```

Strip the query and fragment from the logged URL (as above) so any consumer-supplied secrets in query params don't end up in your logs.

## Production usage

The SDK uses [CuyZ/Valinor](https://github.com/CuyZ/Valinor) to map JSON ↔ DTOs. The mapping is expensive *without a cache*: Valinor introspects every target class on first use, then compiles the mapping. For production, share a single `Client` across the request lifecycle and supply cached Valinor builders.

`Client::registerNormalizerTransformers()` is a single-call helper that wires the SDK's `Identifier` serializer **and** the `Payload` null-skipping transformer onto a consumer-supplied `NormalizerBuilder`. Forget either and the SDK will throw at `Client::__construct` with a remediation hint, so misconfiguration is caught at deploy time, not at runtime.

```php
<?php

use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\NormalizerBuilder;
use Setono\Economic\Client\Client;

require_once 'vendor/autoload.php';

$cache = new FileSystemCache('/var/cache/economic');

$mapperBuilder = (new MapperBuilder())->withCache($cache);
$normalizerBuilder = Client::registerNormalizerTransformers(
    (new NormalizerBuilder())->withCache($cache),
);

$client = new Client(
    'API_KEY',
    'API_SECRET',
    mapperBuilder:     $mapperBuilder,
    normalizerBuilder: $normalizerBuilder,
);
```

In development, decorate the cache with Valinor's `FileWatchingCache` so that source-file edits invalidate compiled mappings without needing to clear the cache manually:

```php
$cache = new FileSystemCache('/var/cache/economic');
if ($_ENV['APP_ENV'] === 'dev') {
    $cache = new \CuyZ\Valinor\Cache\FileWatchingCache($cache);
}
```

See [Valinor: Performance and caching](https://valinor.cuyz.io/latest/other/performance-and-cache/) for full details.

**Caveat on `supportDateFormats()`:** the SDK maps timestamp fields (e.g. `Customer::$lastUpdated`) to `\DateTimeImmutable` via Valinor's default date handling, which accepts e-conomic's `2020-02-19T09:18:09Z` format out of the box. Calling `supportDateFormats()` on a custom `MapperBuilder` *replaces* those defaults — if you do, include a format covering e-conomic's timestamps (e.g. `Y-m-d\TH:i:sp`), or mapping will throw `MappingException`.

## Supported endpoints

The SDK currently types the following endpoints. Anything not listed is reachable via the low-level helpers (see [Other requests](#other-requests)) — pull requests adding more endpoints are welcome.

| Resource | Read (typed DTO returned) | Write |
|---|---|---|
| Customers | `$client->customers()->getByNumber(int)`, `->getPage()`, `->paginate()` | `$client->customers()->create(CustomerRequest)`, `->update(int, CustomerRequest)` |
| Products | `$client->products()->getByNumber(string)`, `->getPage()`, `->paginate()` | — |
| Draft orders | `$client->orders()->drafts()->getByNumber(int)`, `->getPage()`, `->paginate()` | `$client->orders()->drafts()->create(DraftOrderRequest)`, `->update(int, DraftOrderRequest)` |
| Sent orders | `$client->orders()->sent()->getByNumber(int)`, `->getPage()`, `->paginate()` | — |
| Booked invoices | `$client->invoices()->booked()->getByNumber(int)`, `->getPage()`, `->paginate()` | — |
| Self / current agreement | `$client->self()->get()` | — |

`Identifier` covers every foreign-key reference the typed request DTOs need:

| Factory | JSON field |
|---|---|
| `Identifier::layout(int)` | `layoutNumber` |
| `Identifier::paymentTerms(int)` | `paymentTermsNumber` |
| `Identifier::customer(int)` | `customerNumber` |
| `Identifier::customerGroup(int)` | `customerGroupNumber` |
| `Identifier::vatZone(int)` | `vatZoneNumber` |
| `Identifier::project(int)` | `projectNumber` |
| `Identifier::deliveryLocation(int)` | `deliveryLocationNumber` |
| `Identifier::product(string)` | `productNumber` |
| `Identifier::unit(int)` | `unitNumber` |
| `Identifier::employee(int)` | `employeeNumber` |
| `Identifier::customerContact(int)` | `customerContactNumber` |
| `Identifier::vendor(int)` | `vendorNumber` |
| `Identifier::departmentalDistribution(int)` | `departmentalDistributionNumber` |

## v2 migration

v2 is a breaking redesign. If you're upgrading from v1.x:

| v1.x | v2.x |
| --- | --- |
| `$client->products()->get(...)` | `$client->products()->getPage(...)` |
| `$client->products()->get(skipPages: $i++)` loop | `foreach ($client->products()->paginate() as $product)` |
| `$client->orders()->getDraft(...)` | `$client->orders()->drafts()->getPage(...)` |
| `$client->orders()->getDraftByNumber(5)` | `$client->orders()->drafts()->getByNumber(5)` |
| `$client->orders()->getSent(...)` | `$client->orders()->sent()->getPage(...)` |
| `$client->orders()->getSentByNumber(5)` | `$client->orders()->sent()->getByNumber(5)` |
| `$client->invoices()->getBooked(...)` | `$client->invoices()->booked()->getPage(...)` |
| `$client->invoices()->getBookedByNumber(5)` | `$client->invoices()->booked()->getByNumber(5)` |
| `new Query([...])` | pass `array` directly |
| `CollectionRequestOptions::asQuery()` | `CollectionRequestOptions::toArray()` |
| `implements *EndpointInterface` | type against the concrete class |
| `$client->setLogger(...)` | wrap your PSR-18 client to log |

`pageSize` is now capped at 1000 (e-conomic's server maximum) — passing a higher value throws `\InvalidArgumentException`.

## Contributing

Pull requests welcome — especially for missing endpoints. Before submitting:

```bash
composer phpunit       # PHPUnit suite
composer analyse       # PHPStan at level: max
composer check-style   # ECS
vendor/bin/rector --dry-run
```

Each new typed endpoint should ship with: a request DTO under `src/Request/<Resource>/`, a response DTO under `src/Response/<Resource>/`, the endpoint class under `src/Client/Endpoint/`, and end-to-end tests using `ScriptedHttpClient` (see `tests/TestDouble/`).

## License

MIT. See [LICENSE](LICENSE).

[ico-version]: https://poser.pugx.org/setono/economic-php-sdk/v/stable
[ico-license]: https://poser.pugx.org/setono/economic-php-sdk/license
[ico-github-actions]: https://github.com/Setono/economic-php-sdk/workflows/build/badge.svg
[ico-code-coverage]: https://codecov.io/gh/Setono/economic-php-sdk/branch/master/graph/badge.svg
[ico-infection]: https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FSetono%2Feconomic-php-sdk%2Fmaster

[link-packagist]: https://packagist.org/packages/setono/economic-php-sdk
[link-github-actions]: https://github.com/Setono/economic-php-sdk/actions
[link-code-coverage]: https://codecov.io/gh/Setono/economic-php-sdk
[link-infection]: https://dashboard.stryker-mutator.io/reports/github.com/Setono/economic-php-sdk/master
