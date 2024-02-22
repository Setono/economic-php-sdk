<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Order;

use Setono\Economic\Response\Line\Line;

final class Order
{
    public ?int $orderNumber = null;

    /** @var list<Line> */
    public array $lines = [];
}
