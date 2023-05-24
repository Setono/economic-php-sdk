<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class NotFoundExceptionTest extends TestCase
{
    /**
     * @test
     */
    public function it_throws_if_status_code_equals_404(): void
    {
        $this->expectException(NotFoundException::class);
        NotFoundException::assert(new Response(404));
    }

    /**
     * @test
     */
    public function it_does_not_throw_if_status_code_is_not_404(): void
    {
        NotFoundException::assert(new Response(403));
        NotFoundException::assert(new Response(405));
        self::assertTrue(true);
    }
}
