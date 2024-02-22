<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Response\Collection\Collection;
use Setono\Economic\Response\Order\DraftOrder;

final class OrdersEndpoint extends Endpoint implements OrdersEndpointInterface
{
    public function getDraftByNumber(int $number): ?DraftOrder
    {
        try {
            $response = $this->client->get(sprintf('orders/drafts/%d', $number));
        } catch (NotFoundException) {
            return null;
        }

        return $this->mapperBuilder->mapper()->map(
            DraftOrder::class,
            $this->createSourceFromResponse($response),
        );
    }

    public function getDraft(CollectionRequestOptions $collectionRequestOptions = null): Collection
    {
        $collectionRequestOptions ??= new CollectionRequestOptions();

        /** @var class-string<Collection<DraftOrder>> $collection */
        $collection = 'Setono\Economic\Response\Collection\Collection<Setono\Economic\Response\Order\DraftOrder>';

        return $this->mapperBuilder->mapper()->map(
            $collection,
            $this->createSourceFromResponse($this->client->get(
                'orders/drafts',
                $collectionRequestOptions->asQuery(),
            )),
        );
    }
}
