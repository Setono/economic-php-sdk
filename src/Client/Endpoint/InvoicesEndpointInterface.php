<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\DTO\BookedInvoice;
use Setono\Economic\DTO\Collection;

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
    public function getBooked(int $skipPages = 0, int $pageSize = 20, string $filter = null, string $sortBy = null): Collection;
}
