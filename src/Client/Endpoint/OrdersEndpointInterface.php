<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Order\Order;

interface OrdersEndpointInterface extends EndpointInterface
{
    /**
     * Get a draft order by number
     *
     * @param int $number This is the order number
     */
    public function getDraftByNumber(int $number): ?Order;

    /**
     * Get draft orders
     *
     * @return Collection<Order>
     */
    public function getDraft(CollectionRequestOptions $collectionRequestOptions = null): Collection;

    /**
     * Get a sent order by number
     *
     * @param int $number This is the order number
     */
    public function getSentByNumber(int $number): ?Order;

    /**
     * Get sent orders
     *
     * @return Collection<Order>
     */
    public function getSent(CollectionRequestOptions $collectionRequestOptions = null): Collection;
}
