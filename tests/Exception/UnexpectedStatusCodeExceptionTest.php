<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class UnexpectedStatusCodeExceptionTest extends TestCase
{
    /**
     * @test
     */
    public function it_does_not_throw_when_status_code_is_within_range(): void
    {
        $exception = new UnexpectedStatusCodeException(new Response(405, [], 'Method not allowed'));
        self::assertSame('The status code was: 405. The body was: Method not allowed.', $exception->getMessage());
    }
}
