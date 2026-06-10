<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Exception\MappingException;
use Setono\Economic\Response\Order\Order;
use Setono\Economic\TestDouble\ScriptedHttpClient;

/**
 * Covers the date formats wired in {@see Client::configureMapperBuilder()} — they REPLACE
 * Valinor's defaults, so every shape e-conomic emits must be proven to map here.
 */
#[CoversClass(Client::class)]
final class ClientDateFormatsTest extends TestCase
{
    #[Test]
    public function timestamp_with_z_suffix_maps(): void
    {
        $order = $this->mapOrder('{"orderNumber":1,"lastUpdated":"2026-04-30T11:22:33Z"}');

        self::assertNotNull($order->lastUpdated);
        self::assertSame('2026-04-30T11:22:33+00:00', $order->lastUpdated->format('Y-m-d\TH:i:sP'));
    }

    #[Test]
    public function timestamp_with_explicit_offset_maps_and_keeps_the_offset(): void
    {
        $order = $this->mapOrder('{"orderNumber":1,"lastUpdated":"2026-04-30T11:22:33+01:00"}');

        self::assertNotNull($order->lastUpdated);
        self::assertSame('2026-04-30T11:22:33+01:00', $order->lastUpdated->format('Y-m-d\TH:i:sP'));
    }

    #[Test]
    public function timestamp_with_fractional_seconds_maps(): void
    {
        $order = $this->mapOrder('{"orderNumber":1,"lastUpdated":"2026-04-30T11:22:33.123456Z"}');

        self::assertNotNull($order->lastUpdated);
        self::assertSame('123456', $order->lastUpdated->format('u'));
    }

    #[Test]
    public function date_only_maps_to_exactly_midnight_utc(): void
    {
        // The `!` in the SDK's `!Y-m-d` format resets the time to 00:00:00 UTC. Without it,
        // PHP fills the time-of-day from the current wall clock — nondeterministic values.
        $order = $this->mapOrder('{"orderNumber":1,"date":"2026-05-01"}');

        self::assertNotNull($order->date);
        self::assertSame('2026-05-01T00:00:00+00:00', $order->date->format('Y-m-d\TH:i:sP'));
        self::assertSame(0, $order->date->getOffset());
    }

    #[Test]
    public function garbage_date_surfaces_as_mapping_exception(): void
    {
        $this->expectException(MappingException::class);

        $this->mapOrder('{"orderNumber":1,"date":"not-a-date"}');
    }

    #[Test]
    public function absent_date_fields_map_to_null(): void
    {
        $order = $this->mapOrder('{"orderNumber":1}');

        self::assertNull($order->date);
        self::assertNull($order->dueDate);
        self::assertNull($order->lastUpdated);
    }

    private function mapOrder(string $body): Order
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/orders/drafts/1', $body)
        ;

        $client = new Client('app', 'agreement', httpClient: $http);
        $order = $client->orders()->drafts()->getByNumber(1);

        self::assertInstanceOf(Order::class, $order);

        return $order;
    }
}
