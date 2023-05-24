<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Psr\Http\Message\ResponseInterface;

final class InternalServerErrorException extends ResponseAwareException
{
    public static function assert(ResponseInterface $response): void
    {
        if ($response->getStatusCode() === 500) {
            throw new self($response);
        }
    }
}
