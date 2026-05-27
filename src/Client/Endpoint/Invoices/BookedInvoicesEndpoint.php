<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint\Invoices;

use Setono\Economic\Client\Endpoint\CollectionEndpoint;
use Setono\Economic\Response\Invoice\BookedInvoice;

/**
 * @extends CollectionEndpoint<BookedInvoice>
 */
final class BookedInvoicesEndpoint extends CollectionEndpoint
{
    public function getByNumber(int $number): ?BookedInvoice
    {
        return $this->getItem($number);
    }

    protected static function getPath(): string
    {
        return 'invoices/booked';
    }

    /**
     * @return class-string<BookedInvoice>
     */
    protected static function getItemClass(): string
    {
        return BookedInvoice::class;
    }
}
