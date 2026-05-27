# Economic PHP SDK

[![Latest Version][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]
[![Mutation testing][ico-infection]][link-infection]

Consume the [E-conomic API](https://restdocs.e-conomic.com/) in PHP.

## Installation

```bash
composer require setono/economic-php-sdk
```

## Usage

### Get a single page
```php
<?php
use Setono\Economic\Client\Client;
use Setono\Economic\Request\CollectionRequestOptions;

$client = new Client('demo', 'demo');

$products = $client
    ->products()
    ->getPage(new CollectionRequestOptions(filter: 'name$like:b', sortBy: 'name'))
;

print_r($products);
```

will output something like:

```text
Setono\Economic\Response\Collection\Collection Object
(
    [collection] => Array
        (
            [0] => Setono\Economic\Response\Product\Product Object
                (
                    [productNumber] => 2
                    [name] => Barred product
                    [salesPrice] => 50
                )

            [1] => Setono\Economic\Response\Product\Product Object
                (
                    [productNumber] => 5
                    [name] => Fountain Pen, Blue
                    [salesPrice] => 30
                )

            [2] => Setono\Economic\Response\Product\Product Object
                (
                    [productNumber] => 1
                    [name] => Noname T-shirt Black
                    [salesPrice] => 70
                )

            [3] => Setono\Economic\Response\Product\Product Object
                (
                    [productNumber] => 3
                    [name] => SIlk Fabric
                    [salesPrice] => 50
                )

        )

    [pagination] => Setono\Economic\Response\Pagination\Pagination Object
        (
            [maxPageSizeAllowed] => 1000
            [skipPages] => 0
            [pageSize] => 20
            [results] => 4
            [resultsWithoutFilter] => 7
            [firstPage] => Setono\Economic\Response\Pagination\Page Object
                (
                    [endpoint] => products
                    [skipPages] => 0
                    [pageSize] => 20
                    [url] => https://restapi.e-conomic.com/products?skippages=0&pagesize=20&filter=name%24like%3Ab&sort=name
                )

            [lastPage] => Setono\Economic\Response\Pagination\Page Object
                (
                    [endpoint] => products
                    [skipPages] => 0
                    [pageSize] => 20
                    [url] => https://restapi.e-conomic.com/products?skippages=0&pagesize=20&filter=name%24like%3Ab&sort=name
                )

            [nextPage] =>
        )

)
```
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
foreach ($client->orders()->drafts()->paginate() as $order) { /* ... */ }
foreach ($client->orders()->sent()->paginate() as $order) { /* ... */ }
foreach ($client->invoices()->booked()->paginate() as $invoice) { /* ... */ }
```

### Ping / who am I

```php
$self = $client->self()->get();
// $self->raw contains the full /self response
```

`Client::self()` returns a `SelfEndpoint` (cheap, no HTTP). `SelfEndpoint::get()` performs `GET /self` on first call and memoizes the DTO for subsequent calls.

### Error handling

```php
use Setono\Economic\Exception\EconomicException;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Exception\ValidationException;

try {
    $product = $client->products()->getByNumber('does-not-exist');
} catch (NotFoundException $e) {
    // also handled implicitly: getByNumber returns null on 404
    // but custom requests via $client->request() throw
} catch (ValidationException $e) {
    // e-conomic's structured validation errors:
    $errors = $e->getValidationErrors(); // raw nested document; see e-conomic docs
    $hint = $e->getDeveloperHint();
    $logId = $e->getLogId(); // include in support tickets
} catch (EconomicException $e) {
    // marker interface catches every SDK exception
}
```

The exception hierarchy maps HTTP status codes to typed exceptions: `UnauthorizedException` (401), `ForbiddenException` (403), `NotFoundException` (404), `MethodNotAllowedException` (405), `ValidationException` (400/422), `InternalServerErrorException` (500), `NotImplementedException` (501). All extend `ResponseAwareException` and implement `EconomicException`. Other non-2xx codes (415, 429, 502, 504, …) surface as `UnexpectedStatusCodeException`.

### Raw access (when a field isn't typed yet)

Every entry-point DTO (`Product`, `Order`, `BookedInvoice`, `Self_`, `Collection<T>`) carries a `public array $raw` with the full decoded JSON for that response. Nested DTOs don't carry `$raw` — reach them via the parent's `$raw['nested-key']`.

```php
$product = $client->products()->getByNumber('5');
$product->name;                    // typed
$product->raw['costPrice'] ?? null; // any field e-conomic returns
```

### Other requests

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

## Production usage

Internally this library uses the [CuyZ/Valinor](https://github.com/CuyZ/Valinor) library which is particularly well suited
for turning API responses in DTOs. However, this library has some overhead and works best with a cache enabled.

When you instantiate the `Client` you can provide a `MapperBuilder` instance. Use this opportunity to set a cache:

```php
<?php

use CuyZ\Valinor\Cache\FileSystemCache;use CuyZ\Valinor\MapperBuilder;use Setono\Economic\Client\Client;use Setono\Economic\DTO\Box;

require_once '../vendor/autoload.php';

$cache = new FileSystemCache('path/to/cache-directory');
$client = new Client('API_KEY', 'API_SECRET', (new MapperBuilder())->withCache($cache));
```

You can read more about it here: [Valinor: Performance and caching](https://valinor.cuyz.io/latest/other/performance-and-cache/).

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

[ico-version]: https://poser.pugx.org/setono/economic-php-sdk/v/stable
[ico-license]: https://poser.pugx.org/setono/economic-php-sdk/license
[ico-github-actions]: https://github.com/Setono/economic-php-sdk/workflows/build/badge.svg
[ico-code-coverage]: https://codecov.io/gh/Setono/economic-php-sdk/branch/master/graph/badge.svg
[ico-infection]: https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FSetono%2Feconomic-php-sdk%2Fmaster

[link-packagist]: https://packagist.org/packages/setono/economic-php-sdk
[link-github-actions]: https://github.com/Setono/economic-php-sdk/actions
[link-code-coverage]: https://codecov.io/gh/Setono/economic-php-sdk
[link-infection]: https://dashboard.stryker-mutator.io/reports/github.com/Setono/economic-php-sdk/master
