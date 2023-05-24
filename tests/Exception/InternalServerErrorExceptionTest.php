<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class InternalServerErrorExceptionTest extends TestCase
{
    /**
     * @test
     */
    public function it_throws_if_status_code_equals_500(): void
    {
        $this->expectException(InternalServerErrorException::class);
        InternalServerErrorException::assert(new Response(500));
    }

    /**
     * @test
     */
    public function it_does_not_throw_if_status_code_is_not_500(): void
    {
        NotFoundException::assert(new Response(499));
        NotFoundException::assert(new Response(501));
        self::assertTrue(true);
    }
}
