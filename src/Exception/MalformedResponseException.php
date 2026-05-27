<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

/**
 * Thrown when a 2xx response from e-conomic could not be decoded as the JSON object the SDK expects.
 *
 * The HTTP status indicated success, but the body was either not valid JSON at all or did not
 * decode to a top-level object/array. The PSR response is preserved on the exception (via
 * `getResponse()` from the base class); the lazy-parse getters (`getErrorCode()`, `getLogId()`,
 * `getValidationErrors()`, etc.) degrade gracefully to `null` / `[]` because the body is junk.
 */
final class MalformedResponseException extends ResponseAwareException
{
}
