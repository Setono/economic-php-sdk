<?php

declare(strict_types=1);

namespace Setono\Economic\DTO;

final class BookedInvoice
{
    public ?string $bookedInvoiceNumber = null;

    /** @var list<Line> */
    public array $lines = [];
}
