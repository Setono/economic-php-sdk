<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Request\Customer\CustomerRequest;
use Setono\Economic\Response\Customer\Customer;

/**
 * @extends CollectionEndpoint<Customer>
 */
final class CustomersEndpoint extends CollectionEndpoint
{
    public function getByNumber(int $number): ?Customer
    {
        return $this->getItem($number);
    }

    /**
     * POST the given customer payload and return the created {@see Customer}.
     *
     * Delegates to {@see ResourceEndpoint::createOne()} — the response is mapped through the
     * same Valinor pipeline as {@see self::getByNumber()}; untyped fields land in
     * {@see Customer::$raw}. A non-2xx response propagates as the matching typed exception
     * (e.g. `ValidationException` for 400 / 422).
     */
    public function create(CustomerRequest $request): Customer
    {
        return $this->createOne($request);
    }

    protected static function getPath(): string
    {
        return 'customers';
    }

    /**
     * @return class-string<Customer>
     */
    protected static function getItemClass(): string
    {
        return Customer::class;
    }
}
