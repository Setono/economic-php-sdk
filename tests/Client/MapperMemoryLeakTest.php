<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\TestDouble\ScriptedHttpClient;

/**
 * Regression guard for https://github.com/Setono/economic-php-sdk/issues/7.
 *
 * With a non-Closure converter registered on the MapperBuilder, Valinor's converter pipeline
 * leaks a `ReflectionFunction` + closure per converted value node into a static cache GC cannot
 * touch (https://github.com/CuyZ/Valinor/issues/800) — ~70KB per mapped object. The SDK's former
 * `RawStamper` converter (an invokable object) triggered exactly that; `$raw` is now stamped in
 * `ResourceEndpoint::mapResource()` instead, and `Client::configureMapperBuilder()` deliberately
 * registers no converters. (Verified while writing this test: re-adding an invokable no-op
 * converter makes the assertion below fail with ~33MB growth.)
 *
 * The assertion is on `memory_get_usage()` rather than on Valinor's private static cache:
 * it survives Valinor refactors and catches ANY reintroduced per-mapping growth, not just
 * this one bug.
 */
#[CoversClass(Client::class)]
final class MapperMemoryLeakTest extends TestCase
{
    #[Test]
    public function repeated_collection_mapping_does_not_grow_memory(): void
    {
        $http = new ScriptedHttpClient()
            ->on(
                'https://restapi.e-conomic.com/products?skippages=0&pagesize=20',
                (string) json_encode(self::envelope()),
            );

        $client = new Client('app', 'agreement', httpClient: $http);
        $products = $client->products();

        // Sanity check that the envelope actually maps before measuring anything — this call
        // doubles as the first warm-up iteration.
        self::assertCount(20, $products->getPage()->collection);

        // Warm-up: fill Valinor's legitimate one-time caches (class reflection, compiled
        // definitions) so the measured loop sees the steady state only.
        for ($i = 1; $i < 10; ++$i) {
            $products->getPage();
        }

        $http->sentRequests = []; // the fake journals every request — don't measure that
        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 0; $i < 50; ++$i) {
            $products->getPage();
        }

        $http->sentRequests = [];
        gc_collect_cycles();
        $after = memory_get_usage();

        // Before the fix, 50 pages × 20 items grew memory by tens of MB (the leak was ~70KB
        // per mapped object — and each product, unit, line, … is one). A healthy pipeline
        // grows by ~0 bytes here, so 1MB discriminates by orders of magnitude in both
        // directions. If this fails, something leaks per mapping again — do NOT fix it by
        // raising the threshold.
        self::assertLessThan(
            1_000_000,
            $after - $before,
            sprintf('Mapping 50 collection pages grew memory by %d bytes — a per-mapping leak is back (see issue #7).', $after - $before),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function envelope(): array
    {
        $items = [];
        for ($i = 1; $i <= 20; ++$i) {
            $items[] = [
                'productNumber' => (string) $i,
                'name' => sprintf('Product %d', $i),
                'costPrice' => $i + 0.25,
                'salesPrice' => $i + 1.5,
                'lastUpdated' => '2026-01-01T00:00:00Z',
                'unit' => ['unitNumber' => 1, 'name' => 'pcs'],
            ];
        }

        return [
            'collection' => $items,
            'pagination' => [
                'maxPageSizeAllowed' => 1000,
                'skipPages' => 0,
                'pageSize' => 20,
                'results' => 20,
                'resultsWithoutFilter' => 20,
                'firstPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'lastPage' => ['url' => 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20'],
                'nextPage' => null,
            ],
        ];
    }
}
