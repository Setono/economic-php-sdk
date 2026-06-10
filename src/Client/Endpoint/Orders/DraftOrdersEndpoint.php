<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint\Orders;

use Setono\Economic\Client\Endpoint\CollectionEndpoint;
use Setono\Economic\Request\Order\DraftOrderRequest;
use Setono\Economic\Response\Order\Order;

/**
 * @extends CollectionEndpoint<Order>
 */
final class DraftOrdersEndpoint extends CollectionEndpoint
{
    public function getByNumber(int $number): ?Order
    {
        return $this->getItem($number);
    }

    /**
     * POST the given draft-order payload and return the created {@see Order}.
     *
     * Delegates to {@see \Setono\Economic\Client\Endpoint\ResourceEndpoint::createOne()} —
     * the response is mapped through the same Valinor pipeline as {@see self::getByNumber()};
     * untyped fields land in {@see Order::$raw}. A non-2xx response propagates as the matching
     * typed exception (e.g. `ValidationException` for 400 / 422).
     */
    public function create(DraftOrderRequest $request): Order
    {
        return $this->createOne($request);
    }

    protected static function getPath(): string
    {
        return 'orders/drafts';
    }

    /**
     * @return class-string<Order>
     */
    protected static function getItemClass(): string
    {
        return Order::class;
    }
}
