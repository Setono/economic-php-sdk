<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Order;

use Setono\Economic\Response\Line\Line;
use Setono\Economic\Response\Resource;

final class Order extends Resource
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public readonly ?int $orderNumber = null,
        public readonly array $lines = [],
    ) {
    }
}
