<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Psr\Http\Message\ResponseInterface;

final class NotFoundException extends ResponseAwareException
{
    public static function assert(ResponseInterface $response): void
    {
        if ($response->getStatusCode() === 404) {
            throw new self($response);
        }
    }
}
