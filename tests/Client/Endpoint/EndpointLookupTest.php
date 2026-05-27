<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Client\Endpoint\Invoices\BookedInvoicesEndpoint;
use Setono\Economic\Client\Endpoint\Orders\DraftOrdersEndpoint;
use Setono\Economic\Client\Endpoint\Orders\SentOrdersEndpoint;
use Setono\Economic\Response\Invoice\BookedInvoice;
use Setono\Economic\Response\Product\Product;
use Setono\Economic\TestDouble\ScriptedHttpClient;

#[CoversClass(ProductsEndpoint::class)]
#[CoversClass(DraftOrdersEndpoint::class)]
#[CoversClass(SentOrdersEndpoint::class)]
#[CoversClass(BookedInvoicesEndpoint::class)]
#[CoversClass(SelfEndpoint::class)]
final class EndpointLookupTest extends TestCase
{
    #[Test]
    public function products_get_by_number_hits_correct_url(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/products/5', '{"productNumber":"5","name":"Foo"}');

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $product = $client->products()->getByNumber('5');

        self::assertInstanceOf(Product::class, $product);
        self::assertSame('5', $product->productNumber);
        self::assertSame('Foo', $product->name);
    }

    #[Test]
    public function products_get_by_number_returns_null_on_404(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/products/missing', new Response(404));

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        self::assertNull($client->products()->getByNumber('missing'));
    }

    #[Test]
    public function draft_orders_get_by_number_hits_correct_url(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/orders/drafts/42', '{"orderNumber":42}');

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $order = $client->orders()->drafts()->getByNumber(42);

        self::assertNotNull($order);
        self::assertSame(42, $order->orderNumber);
    }

    #[Test]
    public function sent_orders_get_by_number_hits_correct_url(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/orders/sent/77', '{"orderNumber":77}');

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $order = $client->orders()->sent()->getByNumber(77);

        self::assertNotNull($order);
        self::assertSame(77, $order->orderNumber);
    }

    #[Test]
    public function booked_invoices_get_by_number_hits_correct_url(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/invoices/booked/9001', '{"bookedInvoiceNumber":9001}');

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $invoice = $client->invoices()->booked()->getByNumber(9001);

        self::assertInstanceOf(BookedInvoice::class, $invoice);
        self::assertSame(9001, $invoice->bookedInvoiceNumber);
    }

    #[Test]
    public function self_endpoint_fetches_once_and_memoizes(): void
    {
        $http = new ScriptedHttpClient()
            ->on('https://restapi.e-conomic.com/self', '{"loggedInUserType":"User","serverTime":"2026-01-01T00:00:00Z"}');

        $client = new Client('app', 'agreement');
        $client->setHttpClient($http);

        $first = $client->self()->get();
        $second = $client->self()->get();

        self::assertSame($first, $second, 'second call must return the memoized instance');
        self::assertCount(1, $http->sentRequests, 'second call must not hit HTTP');
    }
}
