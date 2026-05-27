<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint\Orders;

use Setono\Economic\Client\Endpoint\CollectionEndpoint;
use Setono\Economic\Response\Order\Order;

/**
 * @extends CollectionEndpoint<Order>
 */
final class SentOrdersEndpoint extends CollectionEndpoint
{
    public function getByNumber(int $number): ?Order
    {
        return $this->getItem($number);
    }

    protected static function getPath(): string
    {
        return 'orders/sent';
    }

    /**
     * @return class-string<Order>
     */
    protected static function getItemClass(): string
    {
        return Order::class;
    }
}
