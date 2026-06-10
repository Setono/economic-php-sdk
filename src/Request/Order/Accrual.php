<?php

declare(strict_types=1);

namespace Setono\Economic\Request\Order;

use Setono\Economic\Request\Payload;

/**
 * Optional accrual period on an order line. Both dates are ISO-8601 (YYYY-MM-DD).
 */
final readonly class Accrual implements Payload
{
    public function __construct(
        public ?string $startDate = null,
        public ?string $endDate = null,
    ) {
    }
}
