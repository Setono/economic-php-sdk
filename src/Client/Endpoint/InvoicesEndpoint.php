<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Client\Endpoint\Invoices\BookedInvoicesEndpoint;

/**
 * Dispatcher endpoint for `/invoices`. Owns no collection methods itself — it lazily
 * exposes state-specific leaf sub-endpoints (`booked()`; future: `drafts()`, `paid()`,
 * `unpaid()`, `overdue()`, `notDue()`, `sent()`).
 */
final class InvoicesEndpoint extends Endpoint
{
    private ?BookedInvoicesEndpoint $booked = null;

    public function booked(): BookedInvoicesEndpoint
    {
        return $this->booked ??= new BookedInvoicesEndpoint($this->client, $this->mapperBuilder);
    }
}
