<?php

declare(strict_types=1);

namespace Setono\Economic\Response;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Product\Product;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(Resource::class)]
#[CoversClass(Product::class)]
#[CoversClass(Collection::class)]
final class ResourceRawTest extends TestCase
{
    #[Test]
    public function product_carries_raw_with_untyped_fields(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/products/5',
                '{"productNumber":"5","name":"Foo","costPrice":12.34,"unit":{"name":"kg"}}',
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $product = $client->products()->getByNumber('5');

        self::assertNotNull($product);
        // typed fields are populated
        self::assertSame('5', $product->productNumber);
        self::assertSame('Foo', $product->name);
        // and $raw carries the full decoded response — including fields we never typed
        self::assertSame('5', $product->raw['productNumber']);
        self::assertSame(12.34, $product->raw['costPrice']);
        self::assertSame(['name' => 'kg'], $product->raw['unit']);
    }

    #[Test]
    public function collection_raw_contains_entire_envelope(): void
    {
        $envelope = [
            'collection' => [['productNumber' => '1', 'name' => 'a']],
            'pagination' => [
                'maxPageSizeAllowed' => 1000,
                'skipPages' => 0,
                'pageSize' => 20,
                'results' => 1,
                'resultsWithoutFilter' => 1,
                'firstPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'lastPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'nextPage' => null,
            ],
            'metaData' => ['serverTime' => '2026-01-01T00:00:00Z'],
        ];

        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/products?skippages=0&pagesize=20',
                (string) json_encode($envelope),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $page = $client->products()->getPage();

        // $raw is the entire response envelope — including fields the SDK doesn't type (here, "metaData").
        self::assertSame($envelope, $page->raw);
    }

    #[Test]
    public function each_item_inside_a_collection_also_carries_raw(): void
    {
        $rawItems = [
            ['productNumber' => '1', 'name' => 'first', 'costPrice' => 1.1],
            ['productNumber' => '2', 'name' => 'second', 'costPrice' => 2.2],
        ];
        $envelope = [
            'collection' => $rawItems,
            'pagination' => [
                'maxPageSizeAllowed' => 1000,
                'skipPages' => 0,
                'pageSize' => 20,
                'results' => 2,
                'resultsWithoutFilter' => 2,
                'firstPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'lastPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'nextPage' => null,
            ],
        ];

        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/products?skippages=0&pagesize=20',
                (string) json_encode($envelope),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $page = $client->products()->getPage();

        self::assertCount(2, $page->collection);
        // Each item's $raw is the per-item dict from the response body, including untyped fields
        // (e.g. costPrice on Product) — parity with getByNumber().
        self::assertSame($rawItems[0], $page->collection[0]->raw);
        self::assertSame($rawItems[1], $page->collection[1]->raw);
    }

    #[Test]
    public function items_yielded_by_paginate_also_carry_raw(): void
    {
        $rawItems = [
            ['productNumber' => '1', 'name' => 'first', 'costPrice' => 1.1],
            ['productNumber' => '2', 'name' => 'second', 'costPrice' => 2.2],
        ];
        $envelope = [
            'collection' => $rawItems,
            'pagination' => [
                'maxPageSizeAllowed' => 1000,
                'skipPages' => 0,
                'pageSize' => 20,
                'results' => 2,
                'resultsWithoutFilter' => 2,
                'firstPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'lastPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'nextPage' => null,
            ],
        ];

        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/products?skippages=0&pagesize=20',
                (string) json_encode($envelope),
            );

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $items = iterator_to_array($client->products()->paginate(), false);

        self::assertCount(2, $items);
        self::assertSame($rawItems[0], $items[0]->raw);
        self::assertSame($rawItems[1], $items[1]->raw);
    }
}
