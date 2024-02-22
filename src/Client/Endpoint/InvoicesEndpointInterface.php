<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Invoice\BookedInvoice;

interface InvoicesEndpointInterface extends EndpointInterface
{
    /**
     * @param int $number This is the 'booked invoice number'
     */
    public function getBookedByNumber(int $number): ?BookedInvoice;

    /**
     * Get booked invoices
     *
     * @return Collection<BookedInvoice>
     */
    public function getBooked(CollectionRequestOptions $collectionRequestOptions = null): Collection;
}
