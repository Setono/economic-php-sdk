<?php

declare(strict_types=1);

namespace Setono\Economic\Response;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Customer\Customer;
use Setono\Economic\Response\Line\Line;
use Setono\Economic\Response\Order\Order;
use Setono\Economic\Response\Pagination\Page;
use Setono\Economic\Response\Pagination\Pagination;
use Setono\Economic\Response\Product\Product;
use Setono\Economic\Response\Reference\CustomerContact;
use Setono\Economic\Response\Reference\PaymentTerms;

#[CoversClass(RawStamper::class)]
final class RawStamperTest extends TestCase
{
    #[Test]
    public function it_stamps_the_full_data_onto_a_top_level_resource(): void
    {
        $product = new Product(productNumber: '5', name: 'Foo');
        $data = [
            'productNumber' => '5',
            'name' => 'Foo',
            'self' => 'https://restapi.e-conomic.com/products/5',
            'metaData' => ['some' => 'links'],
        ];

        RawStamper::stamp($product, $data);

        self::assertSame($data, $product->raw);
    }

    #[Test]
    public function it_stamps_the_collection_envelope_and_each_item_with_its_positional_slice(): void
    {
        $collection = new Collection(
            collection: [new Product(productNumber: '1'), new Product(productNumber: '2')],
            pagination: self::pagination(results: 2),
        );
        $data = [
            'collection' => [
                ['productNumber' => '1', 'name' => 'first'],
                ['productNumber' => '2', 'name' => 'second'],
            ],
            'pagination' => ['results' => 2],
            'metaData' => ['some' => 'links'],
        ];

        RawStamper::stamp($collection, $data);

        self::assertSame($data, $collection->raw);
        self::assertSame($data['collection'][0], $collection->collection[0]->raw);
        self::assertSame($data['collection'][1], $collection->collection[1]->raw);
    }

    #[Test]
    public function it_stamps_a_nested_resource_with_its_slice(): void
    {
        // `paymentTerms` is declared before `customer` on Order — having both present proves
        // the walk continues past an already-handled object property instead of stopping at
        // the first one.
        $order = new Order(
            orderNumber: 42,
            paymentTerms: new PaymentTerms(paymentTermsNumber: 2),
            customer: new Customer(customerNumber: 1),
        );
        $data = [
            'orderNumber' => 42,
            'paymentTerms' => ['paymentTermsNumber' => 2],
            'customer' => ['customerNumber' => 1, 'self' => 'https://restapi.e-conomic.com/customers/1'],
        ];

        RawStamper::stamp($order, $data);

        self::assertSame($data, $order->raw);
        self::assertNotNull($order->customer);
        self::assertSame($data['customer'], $order->customer->raw);
    }

    #[Test]
    public function it_descends_through_non_resource_dtos_to_reach_nested_resources(): void
    {
        // Line is NOT a Resource (no $raw of its own) but carries one — the walker must
        // descend through it, matching by property name and list position.
        $order = new Order(orderNumber: 42, lines: [new Line(lineNumber: 1, product: new Product(productNumber: 'A'))]);
        $data = [
            'orderNumber' => 42,
            'lines' => [
                ['lineNumber' => 1, 'product' => ['productNumber' => 'A', 'costPrice' => 12.34]],
            ],
        ];

        RawStamper::stamp($order, $data);

        self::assertNotNull($order->lines[0]->product);
        self::assertSame($data['lines'][0]['product'], $order->lines[0]->product->raw);
    }

    #[Test]
    public function it_handles_the_recursive_customer_contact_customer_graph(): void
    {
        // The TYPE graph is recursive (Customer → CustomerContact → Customer); the walker
        // terminates because the decoded DATA is acyclic.
        $customer = new Customer(
            customerNumber: 1,
            customerContact: new CustomerContact(customerContactNumber: 3, customer: new Customer(customerNumber: 7)),
        );
        $data = [
            'customerNumber' => 1,
            'customerContact' => [
                'customerContactNumber' => 3,
                'customer' => ['customerNumber' => 7, 'self' => 'https://restapi.e-conomic.com/customers/7'],
            ],
        ];

        RawStamper::stamp($customer, $data);

        self::assertSame($data, $customer->raw);
        self::assertNotNull($customer->customerContact);
        self::assertNotNull($customer->customerContact->customer);
        self::assertSame($data['customerContact']['customer'], $customer->customerContact->customer->raw);
    }

    #[Test]
    public function a_nested_resource_whose_slice_is_missing_keeps_an_empty_raw(): void
    {
        $order = new Order(orderNumber: 42, customer: new Customer(customerNumber: 1));
        $data = ['orderNumber' => 42];

        RawStamper::stamp($order, $data);

        self::assertSame($data, $order->raw);
        self::assertNotNull($order->customer);
        self::assertSame([], $order->customer->raw);
    }

    #[Test]
    public function a_non_array_slice_blocks_descent_without_errors(): void
    {
        // A scalar where the walker expects an object slice must not be stamped or descended
        // into — this is also what naturally blocks descent into \DateTimeImmutable properties,
        // whose wire format is a string.
        $order = new Order(
            orderNumber: 42,
            date: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            customer: new Customer(customerNumber: 1),
        );
        $data = [
            'orderNumber' => 42,
            'date' => '2026-01-01T00:00:00Z',
            'customer' => 'not-an-array',
        ];

        RawStamper::stamp($order, $data);

        self::assertSame($data, $order->raw);
        self::assertNotNull($order->customer);
        self::assertSame([], $order->customer->raw);
    }

    #[Test]
    public function a_null_property_with_an_array_slice_present_is_ignored(): void
    {
        $order = new Order(orderNumber: 42, customer: null);
        $data = [
            'orderNumber' => 42,
            'customer' => ['customerNumber' => 1],
        ];

        RawStamper::stamp($order, $data);

        self::assertSame($data, $order->raw);
        self::assertNull($order->customer);
    }

    #[Test]
    public function list_items_without_an_aligned_array_slice_are_skipped(): void
    {
        $order = new Order(
            orderNumber: 42,
            lines: [
                new Line(lineNumber: 1, product: new Product(productNumber: 'A')),
                new Line(lineNumber: 2, product: new Product(productNumber: 'B')),
            ],
        );
        // Index 0 carries a scalar, index 1 is missing entirely — neither line can be
        // stamped, and the walker must not error on either.
        $data = [
            'orderNumber' => 42,
            'lines' => ['scalar-at-index-0'],
        ];

        RawStamper::stamp($order, $data);

        self::assertNotNull($order->lines[0]->product);
        self::assertSame([], $order->lines[0]->product->raw);
        self::assertNotNull($order->lines[1]->product);
        self::assertSame([], $order->lines[1]->product->raw);
    }

    #[Test]
    public function a_literal_raw_key_in_the_data_is_kept_in_raw_but_never_walked(): void
    {
        // After stamping, the object's $raw property holds the full data. The walker skips
        // the property by name so it never treats the just-stamped array as a slice to
        // descend into.
        $product = new Product(productNumber: '5');
        $data = [
            'productNumber' => '5',
            'raw' => ['x' => 'y'],
        ];

        RawStamper::stamp($product, $data);

        self::assertSame($data, $product->raw);
    }

    private static function pagination(int $results): Pagination
    {
        return new Pagination(
            maxPageSizeAllowed: 1000,
            skipPages: 0,
            pageSize: 20,
            results: $results,
            resultsWithoutFilter: $results,
            firstPage: new Page('https://restapi.e-conomic.com/products?skippages=0&pagesize=20'),
            lastPage: new Page('https://restapi.e-conomic.com/products?skippages=0&pagesize=20'),
            nextPage: null,
        );
    }
}
