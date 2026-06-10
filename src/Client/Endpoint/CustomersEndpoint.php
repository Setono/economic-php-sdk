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

    /**
     * PUT the given customer payload to `customers/{$number}` and return the updated
     * {@see Customer}. Delegates to {@see ResourceEndpoint::updateOne()} — same Valinor
     * pipeline and `$raw` stamping as {@see self::create()}.
     *
     * WARNING: e-conomic PUT is full-replace. Any field absent from the serialized body —
     * including `null` properties (stripped by the `Payload` transformer) and schema fields
     * not modeled on {@see CustomerRequest} (`priceGroup`, `customerContact`, `attention`,
     * `defaultDeliveryLocation`, …) — is cleared server-side. Use
     * {@see CustomerRequest::fromResponse()} to prefill a request from a fetched customer,
     * or hand-build the body and use `Client::request()` for unmodeled fields.
     *
     * Exception: `eInvoicingDisabledByDefault` is "updatable only by using PATCH to
     * /customers/:customerNumber" per the e-conomic docs — its value in a PUT body is
     * ignored, so changing it on `$request` has no effect through this method (and it is
     * not cleared by omission either).
     */
    public function update(int $number, CustomerRequest $request): Customer
    {
        return $this->updateOne($number, $request);
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
