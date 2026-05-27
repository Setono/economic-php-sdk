<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

/**
 * Thrown for 400 Bad Request and 422 Unprocessable Entity responses.
 *
 * Both carry e-conomic's structured validation error document — see
 * `getValidationErrors()` for the nested shape that mirrors the request payload.
 */
final class ValidationException extends ClientErrorException
{
}
