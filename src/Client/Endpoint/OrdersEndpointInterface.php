<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Order\DraftOrder;

interface OrdersEndpointInterface extends EndpointInterface
{
    /**
     * Get a draft order by number
     *
     * @param int $number This is the 'order number'
     */
    public function getDraftByNumber(int $number): ?DraftOrder;

    /**
     * Get draft orders
     *
     * @return Collection<DraftOrder>
     */
    public function getDraft(CollectionRequestOptions $collectionRequestOptions = null): Collection;
}
