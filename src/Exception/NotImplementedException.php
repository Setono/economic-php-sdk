<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

/**
 * Thrown for 501 Not Implemented responses.
 *
 * e-conomic uses this for endpoints that are linked from other resources but not yet
 * actually implemented in production.
 */
final class NotImplementedException extends ServerErrorException
{
}
