<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Client\Query\CollectionQuery;
use Setono\Economic\DTO\Collection;
use Setono\Economic\DTO\DraftOrder;
use Setono\Economic\Exception\NotFoundException;

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

    public function getDraft(int $skipPages = 0, int $pageSize = 20, string $filter = null, string $sortBy = null): Collection
    {
        /** @var class-string<Collection<DraftOrder>> $collection */
        $collection = 'Setono\Economic\DTO\Collection<Setono\Economic\DTO\DraftOrder>';

        return $this->mapperBuilder->mapper()->map(
            $collection,
            $this->createSourceFromResponse($this->client->get(
                'orders/drafts',
                new CollectionQuery($skipPages, $pageSize, $filter, $sortBy),
            )),
        );
    }
}
