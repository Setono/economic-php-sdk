<?php

declare(strict_types=1);

namespace Setono\Economic\DTO;

final class Line
{
    public ?Product $product = null;

    public ?float $quantity = null;
}
