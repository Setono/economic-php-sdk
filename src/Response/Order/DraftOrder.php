<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Order;

use Setono\Economic\Response\Line\Line;

final class DraftOrder
{
    public ?int $orderNumber = null;

    /** @var list<Line> */
    public array $lines = [];
}
