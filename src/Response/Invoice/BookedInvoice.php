<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Invoice;

use Setono\Economic\Response\Line\Line;

final class BookedInvoice
{
    public ?int $bookedInvoiceNumber = null;

    /** @var list<Line> */
    public array $lines = [];
}
