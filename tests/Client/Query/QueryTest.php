<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Query;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Setono\Economic\Client\Query\Query
 */
final class QueryTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_query_string(): void
    {
        $query = new Query([
            'skippages' => 0,
            'pagesize' => 20,
            'filter' => 'name$like:b',
            'sort' => 'name',
        ]);

        self::assertFalse($query->isEmpty());
        self::assertSame('skippages=0&pagesize=20&filter=name%24like%3Ab&sort=name', $query->toString());
    }
}
