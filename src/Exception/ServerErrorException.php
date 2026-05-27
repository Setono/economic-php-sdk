<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

/**
 * Base class for all 5xx Server Error exceptions thrown by this SDK.
 */
abstract class ServerErrorException extends ResponseAwareException
{
}
