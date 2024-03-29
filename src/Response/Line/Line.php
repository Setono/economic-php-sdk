<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Line;

use Setono\Economic\Response\Product\Product;

final class Line
{
    public ?int $lineNumber = null;

    public ?Product $product = null;

    public ?float $quantity = null;
}
