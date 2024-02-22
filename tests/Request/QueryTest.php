<?php

declare(strict_types=1);

namespace Setono\Economic\Request;

use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    /**
     * @test
     */
    public function it_has_defaults(): void
    {
        $query = new Query();

        self::assertTrue($query->isEmpty());
        self::assertSame('', $query->toString());
    }

    /**
     * @test
     */
    public function it_generates_query_string(): void
    {
        $query = new Query([
            'foo' => 'bar',
            'baz' => 'qux',
        ]);

        self::assertFalse($query->isEmpty());
        self::assertSame('foo=bar&baz=qux', $query->toString());
    }
}
