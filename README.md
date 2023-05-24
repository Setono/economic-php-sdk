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

### Get a collection
```php
<?php
use Setono\Economic\Client\Client;

$client = new Client('demo', 'demo');

$products = $client
    ->products()
    ->get(filter: 'name$like:b', sortBy: 'name')
;

print_r($products);
```

will output something like:

```text
Setono\Economic\DTO\Collection Object
(
    [collection] => Array
        (
            [0] => Setono\Economic\DTO\Product Object
                (
                    [productNumber] => 2
                    [name] => Barred product
                    [salesPrice] => 50
                )

            [1] => Setono\Economic\DTO\Product Object
                (
                    [productNumber] => 5
                    [name] => Fountain Pen, Blue
                    [salesPrice] => 30
                )

            [2] => Setono\Economic\DTO\Product Object
                (
                    [productNumber] => 1
                    [name] => Noname T-shirt Black
                    [salesPrice] => 70
                )

            [3] => Setono\Economic\DTO\Product Object
                (
                    [productNumber] => 3
                    [name] => SIlk Fabric
                    [salesPrice] => 50
                )

        )

    [pagination] => Setono\Economic\DTO\Pagination Object
        (
            [maxPageSizeAllowed] => 1000
            [skipPages] => 0
            [pageSize] => 20
            [results] => 4
            [resultsWithoutFilter] => 7
            [firstPage] => Setono\Economic\DTO\Page Object
                (
                    [endpoint] => products
                    [skipPages] => 0
                    [pageSize] => 20
                    [url] => https://restapi.e-conomic.com/products?skippages=0&pagesize=20&filter=name%24like%3Ab&sort=name
                )

            [lastPage] => Setono\Economic\DTO\Page Object
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
### Paginate

```php
<?php
use Setono\Economic\Client\Client;

$client = new Client('demo', 'demo');

$skipPages = 0;

do {
    $products = $client
        ->products()
        ->get(skipPages: $skipPages++);
} while(!$products->isEmpty());
```

### Other requests

If the endpoint or method you want to call isn't present yet, you have two options: 1) Create a PR and add the missing parts or 2) use the generic `request` method:

```php
<?php

use Setono\Economic\Client\Client;

require_once '../vendor/autoload.php';

$client = new Client('API_KEY', 'API_SECRET');

/** @var \Psr\Http\Message\ResponseInterface $response */
$response = $client->request(/** @var \Psr\Http\Message\RequestInterface $request */ $request);
```

## Production usage

Internally this library uses the [CuyZ/Valinor](https://github.com/CuyZ/Valinor) library which is particularly well suited
for turning API responses in DTOs. However, this library has some overhead and works best with a cache enabled.

When you instantiate the `Client` you can provide a `MapperBuilder` instance. Use this opportunity to set a cache:

```php
<?php

use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\MapperBuilder;
use Setono\Economic\Client\Client;
use Setono\Economic\DTO\Collection;
use Setono\Economic\DTO\Box;

require_once '../vendor/autoload.php';

$cache = new FileSystemCache('path/to/cache-directory');
$client = new Client('API_KEY', 'API_SECRET', (new MapperBuilder())->withCache($cache));
```

You can read more about it here: [Valinor: Performance and caching](https://valinor.cuyz.io/latest/other/performance-and-cache/).

[ico-version]: https://poser.pugx.org/setono/economic-php-sdk/v/stable
[ico-license]: https://poser.pugx.org/setono/economic-php-sdk/license
[ico-github-actions]: https://github.com/Setono/economic-php-sdk/workflows/build/badge.svg
[ico-code-coverage]: https://codecov.io/gh/Setono/economic-php-sdk/branch/master/graph/badge.svg
[ico-infection]: https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FSetono%2Feconomic-php-sdk%2Fmaster

[link-packagist]: https://packagist.org/packages/setono/economic-php-sdk
[link-github-actions]: https://github.com/Setono/economic-php-sdk/actions
[link-code-coverage]: https://codecov.io/gh/Setono/economic-php-sdk
[link-infection]: https://dashboard.stryker-mutator.io/reports/github.com/Setono/economic-php-sdk/master
