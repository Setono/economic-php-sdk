<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use Setono\Economic\Request\Payload;

/**
 * Free-form note fields displayed on the printed/PDF representation of the order.
 * All fields are optional per the e-conomic schema.
 */
final class Notes implements Payload
{
    public function __construct(
        public ?string $heading = null,
        public ?string $textLine1 = null,
        public ?string $textLine2 = null,
    ) {
    }
}
