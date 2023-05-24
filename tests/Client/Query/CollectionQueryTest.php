<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Query;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Setono\Economic\Client\Query\Query
 * @covers \Setono\Economic\Client\Query\CollectionQuery
 */
final class CollectionQueryTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_query_string(): void
    {
        $query = new CollectionQuery(0, 20, 'name$like:b', 'name');

        self::assertFalse($query->isEmpty());
        self::assertSame('skippages=0&pagesize=20&filter=name%24like%3Ab&sort=name', $query->toString());
    }
}
