<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

use Setono\Economic\Response\Resource;

/**
 * Represents `GET /self` — the agreement and user the current credentials are bound to.
 *
 * Class name uses the trailing-underscore PHP convention because `Self` is a reserved word.
 *
 * Only a small subset of fields is typed here; the rest is reachable via `$self->raw[...]`.
 */
final class Self_ extends Resource
{
    public function __construct(
        public readonly ?string $loggedInUserType = null,
        public readonly ?string $serverTime = null,
    ) {
    }
}
