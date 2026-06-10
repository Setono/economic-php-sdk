<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(CustomersEndpoint::class)]
#[CoversClass(CollectionEndpoint::class)]
final class CustomersPaginationTest extends TestCase
{
    #[Test]
    public function customers_paginate_walks_two_pages(): void
    {
        $base = 'https://restapi.e-conomic.com';

        $http = new ScriptedHttpClient()
            ->on(
                $base . '/customers?skippages=0&pagesize=20',
                self::pageJson([1, 2], nextUrl: $base . '/customers?skippages=1&pagesize=20'),
            )
            ->on(
                $base . '/customers?skippages=1&pagesize=20',
                self::pageJson([3], nextUrl: null),
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);

        $numbers = [];
        foreach ($client->customers()->paginate() as $customer) {
            $numbers[] = $customer->customerNumber;
        }

        self::assertSame([1, 2, 3], $numbers);
        self::assertCount(2, $http->sentRequests, 'paginate should make exactly 2 HTTP requests for 2 pages');
    }

    #[Test]
    public function customers_paginate_empty_first_page_yields_nothing(): void
    {
        $base = 'https://restapi.e-conomic.com';
        $http = new ScriptedHttpClient()
            ->on(
                $base . '/customers?skippages=0&pagesize=20',
                self::pageJson([], nextUrl: null),
            )
        ;

        $client = new Client('app', 'agreement', httpClient: $http);

        $count = 0;
        foreach ($client->customers()->paginate() as $_) {
            ++$count;
        }

        self::assertSame(0, $count);
    }

    /**
     * @param list<int> $numbers
     */
    private static function pageJson(array $numbers, ?string $nextUrl): string
    {
        $items = array_map(static fn (int $n) => ['customerNumber' => $n, 'name' => sprintf('Customer %d', $n)], $numbers);

        $envelope = [
            'collection' => $items,
            'pagination' => [
                'maxPageSizeAllowed' => 1000,
                'skipPages' => 0,
                'pageSize' => 20,
                'results' => count($items),
                'resultsWithoutFilter' => count($items),
                'firstPage' => ['url' => 'https://restapi.e-conomic.com/customers?skippages=0&pagesize=20'],
                'lastPage' => ['url' => 'https://restapi.e-conomic.com/customers?skippages=0&pagesize=20'],
                'nextPage' => null === $nextUrl ? null : ['url' => $nextUrl],
            ],
        ];

        return (string) json_encode($envelope);
    }
}
