<?php

declare(strict_types=1);

namespace Setono\Economic\DTO;

final class DraftOrder
{
    public ?int $orderNumber = null;

    /** @var list<Line> */
    public array $lines = [];
}
