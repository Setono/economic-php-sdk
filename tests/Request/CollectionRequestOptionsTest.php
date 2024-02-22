<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use PHPUnit\Framework\TestCase;

final class CollectionRequestOptionsTest extends TestCase
{
    /**
     * @test
     */
    public function it_has_defaults(): void
    {
        $options = new CollectionRequestOptions();

        self::assertSame(0, $options->skipPages);
        self::assertSame(20, $options->pageSize);
        self::assertNull($options->filter);
        self::assertNull($options->sortBy);
    }

    /**
     * @test
     */
    public function it_can_return_query(): void
    {
        $options = new CollectionRequestOptions(0, 20, 'name$like:b', 'name');
        $query = $options->asQuery();

        self::assertSame('skippages=0&pagesize=20&filter=name%24like%3Ab&sort=name', $query->toString());
    }

    /**
     * @test
     */
    public function it_throws_exception_if_skip_pages_is_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionRequestOptions(-1);
    }

    /**
     * @test
     */
    public function it_throws_exception_if_page_size_is_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionRequestOptions(pageSize: 0);
    }

    /**
     * @test
     */
    public function it_throws_exception_if_page_size_is_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionRequestOptions(pageSize: -1);
    }
}
