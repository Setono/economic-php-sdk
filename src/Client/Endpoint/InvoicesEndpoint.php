<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\DTO\BookedInvoice;
use Setono\Economic\DTO\Collection;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Request\CollectionRequestOptions;

final class InvoicesEndpoint extends Endpoint implements InvoicesEndpointInterface
{
    public function getBookedByNumber(int $number): ?BookedInvoice
    {
        try {
            $response = $this->client->get(sprintf('invoices/booked/%d', $number));
        } catch (NotFoundException) {
            return null;
        }

        return $this->mapperBuilder->mapper()->map(
            BookedInvoice::class,
            $this->createSourceFromResponse($response),
        );
    }

    public function getBooked(CollectionRequestOptions $collectionRequestOptions = null): Collection
    {
        $collectionRequestOptions ??= new CollectionRequestOptions();

        /** @var class-string<Collection<BookedInvoice>> $collection */
        $collection = 'Setono\Economic\DTO\Collection<Setono\Economic\DTO\BookedInvoice>';

        return $this->mapperBuilder->mapper()->map(
            $collection,
            $this->createSourceFromResponse($this->client->get(
                'invoices/booked',
                $collectionRequestOptions->asQuery(),
            )),
        );
    }
}
