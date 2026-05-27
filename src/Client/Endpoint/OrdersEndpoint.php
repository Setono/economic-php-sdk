<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Client\Endpoint\Orders\DraftOrdersEndpoint;
use Setono\Economic\Client\Endpoint\Orders\SentOrdersEndpoint;

/**
 * Dispatcher endpoint for `/orders`. Owns no collection methods itself — it lazily
 * exposes state-specific leaf sub-endpoints (`drafts()`, `sent()`).
 */
final class OrdersEndpoint extends Endpoint
{
    private ?DraftOrdersEndpoint $drafts = null;

    private ?SentOrdersEndpoint $sent = null;

    public function drafts(): DraftOrdersEndpoint
    {
        return $this->drafts ??= new DraftOrdersEndpoint($this->client, $this->mapperBuilder);
    }

    public function sent(): SentOrdersEndpoint
    {
        return $this->sent ??= new SentOrdersEndpoint($this->client, $this->mapperBuilder);
    }
}
