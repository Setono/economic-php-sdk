<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

/**
 * Thrown when the SDK is asked to dispatch a request to a URL it cannot or will not use.
 *
 * Pre-flight failure — there is no PSR response associated with this exception. Today this
 * fires for two reasons inside {@see \Setono\Economic\Client\Client::get()}:
 *  - the consumer passed an absolute URL whose host does not match the SDK's base host
 *    (refusing to leak auth credentials to a different host), or
 *  - the consumer combined an absolute URL with a non-empty `$query` array.
 *
 * Extends PHP's `\InvalidArgumentException`, so existing `catch (\InvalidArgumentException $e)`
 * call sites still match. Also implements `EconomicException` so it's caught by the SDK's
 * marker-interface net.
 */
final class InvalidUrlException extends \InvalidArgumentException implements EconomicException
{
}
