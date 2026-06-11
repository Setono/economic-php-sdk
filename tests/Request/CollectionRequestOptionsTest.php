<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CollectionRequestOptions::class)]
final class CollectionRequestOptionsTest extends TestCase
{
    #[Test]
    public function it_has_defaults(): void
    {
        $options = new CollectionRequestOptions();

        self::assertSame(0, $options->skipPages);
        self::assertSame(20, $options->pageSize);
        self::assertNull($options->filter);
        self::assertNull($options->sortBy);
    }

    #[Test]
    public function it_serializes_to_array(): void
    {
        $options = new CollectionRequestOptions(0, 20, Filter::like('name', 'b'), 'name');

        self::assertSame([
            'skippages' => 0,
            'pagesize' => 20,
            'filter' => 'name$like:b',
            'sort' => 'name',
        ], $options->toArray());
    }

    #[Test]
    public function it_throws_exception_if_skip_pages_is_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionRequestOptions(-1);
    }

    #[Test]
    public function it_throws_exception_if_page_size_is_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionRequestOptions(pageSize: 0);
    }

    #[Test]
    public function it_throws_exception_if_page_size_is_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionRequestOptions(pageSize: -1);
    }

    #[Test]
    public function it_throws_exception_if_page_size_exceeds_server_maximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionRequestOptions(pageSize: 1001);
    }

    #[Test]
    public function it_accepts_page_size_at_server_maximum(): void
    {
        $this->expectNotToPerformAssertions();
        new CollectionRequestOptions(pageSize: 1000);
    }

    #[Test]
    public function it_accepts_a_filter_in_the_constructor(): void
    {
        $options = new CollectionRequestOptions(filter: Filter::eq('name', 'Joe'));

        self::assertSame('name$eq:Joe', $options->filter?->toString());
    }

    #[Test]
    public function with_filter_accepts_a_filter(): void
    {
        $options = CollectionRequestOptions::new()->withFilter(Filter::like('name', 'b'));

        self::assertSame('name$like:b', $options->filter?->toString());
    }

    #[Test]
    public function with_builders_produce_new_instances(): void
    {
        $a = new CollectionRequestOptions();
        $b = $a->withSkipPages(5);
        $c = $b->withPageSize(50);
        $d = $c->withFilter(Filter::eq('name', 'foo'))->withSortBy('bar');

        self::assertNotSame($a, $b);
        self::assertNotSame($b, $c);
        self::assertNotSame($c, $d);
        self::assertSame(0, $a->skipPages);
        self::assertSame(5, $b->skipPages);
        self::assertSame(50, $c->pageSize);
        self::assertSame('name$eq:foo', $d->filter?->toString());
        self::assertSame('bar', $d->sortBy);
    }
}
