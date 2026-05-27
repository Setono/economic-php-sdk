<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Client\Endpoint\Invoices\BookedInvoicesEndpoint;
use Setono\Economic\Client\Endpoint\Orders\DraftOrdersEndpoint;
use Setono\Economic\Client\Endpoint\Orders\SentOrdersEndpoint;
use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(CollectionEndpoint::class)]
#[CoversClass(ProductsEndpoint::class)]
#[CoversClass(DraftOrdersEndpoint::class)]
#[CoversClass(SentOrdersEndpoint::class)]
#[CoversClass(BookedInvoicesEndpoint::class)]
final class PaginationTest extends TestCase
{
    #[Test]
    public function products_paginate_walks_three_pages(): void
    {
        $base = 'https://restapi.e-conomic.com';

        $http = new ScriptedHttpClient()
            ->on(
                $base . '/products?skippages=0&pagesize=20&filter=name%24like%3Ab',
                self::pageJson(['p1a', 'p1b'], nextUrl: $base . '/products?skippages=1&pagesize=20&filter=name%24like%3Ab'),
            )
            ->on(
                $base . '/products?skippages=1&pagesize=20&filter=name%24like%3Ab',
                self::pageJson(['p2'], nextUrl: $base . '/products?skippages=2&pagesize=20&filter=name%24like%3Ab'),
            )
            ->on(
                $base . '/products?skippages=2&pagesize=20&filter=name%24like%3Ab',
                self::pageJson(['p3a', 'p3b', 'p3c'], nextUrl: null),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $names = [];
        foreach ($client->products()->paginate(new CollectionRequestOptions(filter: 'name$like:b')) as $product) {
            $names[] = $product->name;
        }

        self::assertSame(['p1a', 'p1b', 'p2', 'p3a', 'p3b', 'p3c'], $names);
        self::assertCount(3, $http->sentRequests, 'paginate should make exactly 3 HTTP requests');
    }

    #[Test]
    public function draft_orders_paginate_stops_on_null_next_page(): void
    {
        $base = 'https://restapi.e-conomic.com';
        $http = new ScriptedHttpClient()
            ->on(
                $base . '/orders/drafts?skippages=0&pagesize=20',
                self::pageJsonOrders([1, 2], nextUrl: null),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $numbers = [];
        foreach ($client->orders()->drafts()->paginate() as $order) {
            $numbers[] = $order->orderNumber;
        }

        self::assertSame([1, 2], $numbers);
        self::assertCount(1, $http->sentRequests, 'single-page result should make exactly 1 request');
    }

    #[Test]
    public function draft_orders_paginate_empty_first_page_yields_nothing(): void
    {
        $base = 'https://restapi.e-conomic.com';
        $http = new ScriptedHttpClient()
            ->on(
                $base . '/orders/drafts?skippages=0&pagesize=20',
                self::pageJsonOrders([], nextUrl: null),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $count = 0;
        foreach ($client->orders()->drafts()->paginate() as $_) {
            ++$count;
        }

        self::assertSame(0, $count);
    }

    #[Test]
    public function sent_orders_paginate_inherits_the_walker(): void
    {
        $base = 'https://restapi.e-conomic.com';
        $http = new ScriptedHttpClient()
            ->on(
                $base . '/orders/sent?skippages=0&pagesize=20',
                self::pageJsonOrders([10], nextUrl: null),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $items = iterator_to_array($client->orders()->sent()->paginate(), false);

        self::assertCount(1, $items);
        self::assertSame(10, $items[0]->orderNumber);
    }

    #[Test]
    public function booked_invoices_paginate_walks_two_pages(): void
    {
        $base = 'https://restapi.e-conomic.com';
        $http = new ScriptedHttpClient()
            ->on(
                $base . '/invoices/booked?skippages=0&pagesize=20',
                self::pageJsonInvoices([100, 101], nextUrl: $base . '/invoices/booked?skippages=1&pagesize=20'),
            )
            ->on(
                $base . '/invoices/booked?skippages=1&pagesize=20',
                self::pageJsonInvoices([102], nextUrl: null),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $numbers = [];
        foreach ($client->invoices()->booked()->paginate() as $invoice) {
            $numbers[] = $invoice->bookedInvoiceNumber;
        }

        self::assertSame([100, 101, 102], $numbers);
        self::assertCount(2, $http->sentRequests);
    }

    #[Test]
    public function leaf_endpoints_inherit_pagination_machinery_from_collection_endpoint(): void
    {
        $leaves = [ProductsEndpoint::class, DraftOrdersEndpoint::class, SentOrdersEndpoint::class, BookedInvoicesEndpoint::class];
        $inherited = ['getPage', 'paginate'];

        foreach ($leaves as $leaf) {
            foreach ($inherited as $method) {
                $declaring = new \ReflectionMethod($leaf, $method)->getDeclaringClass()->getName();
                self::assertSame(
                    CollectionEndpoint::class,
                    $declaring,
                    sprintf('%s::%s() must be inherited from CollectionEndpoint (got %s)', $leaf, $method, $declaring),
                );
            }
        }
    }

    #[Test]
    public function collection_has_no_pagination_logic(): void
    {
        $reflection = new \ReflectionClass(Collection::class);

        self::assertFalse($reflection->hasMethod('paginate'), 'Collection must not expose paginate()');
        self::assertFalse($reflection->hasMethod('setFetcher'), 'Collection must not expose setFetcher()');
        self::assertFalse($reflection->hasProperty('fetcher'), 'Collection must not carry a $fetcher property');
    }

    /**
     * @param list<string> $names
     */
    private static function pageJson(array $names, ?string $nextUrl): string
    {
        $items = array_map(static fn (string $name) => ['name' => $name], $names);

        return self::envelope($items, $nextUrl);
    }

    /**
     * @param list<int> $numbers
     */
    private static function pageJsonOrders(array $numbers, ?string $nextUrl): string
    {
        $items = array_map(static fn (int $n) => ['orderNumber' => $n], $numbers);

        return self::envelope($items, $nextUrl);
    }

    /**
     * @param list<int> $numbers
     */
    private static function pageJsonInvoices(array $numbers, ?string $nextUrl): string
    {
        $items = array_map(static fn (int $n) => ['bookedInvoiceNumber' => $n], $numbers);

        return self::envelope($items, $nextUrl);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function envelope(array $items, ?string $nextUrl): string
    {
        $envelope = [
            'collection' => $items,
            'pagination' => [
                'maxPageSizeAllowed' => 1000,
                'skipPages' => 0,
                'pageSize' => 20,
                'results' => count($items),
                'resultsWithoutFilter' => count($items),
                'firstPage' => ['url' => 'https://restapi.e-conomic.com/x?skippages=0&pagesize=20'],
                'lastPage' => ['url' => 'https://restapi.e-conomic.com/x?skippages=0&pagesize=20'],
                'nextPage' => null === $nextUrl ? null : ['url' => $nextUrl],
            ],
        ];

        return (string) json_encode($envelope);
    }
}
