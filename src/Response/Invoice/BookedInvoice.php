<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Invoice;

use Setono\Economic\Response\Line\Line;
use Setono\Economic\Response\Resource;

final class BookedInvoice extends Resource
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public readonly ?int $bookedInvoiceNumber = null,
        public readonly array $lines = [],
    ) {
    }
}
