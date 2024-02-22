<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\Economic\Client\Endpoint\InvoicesEndpointInterface;
use Setono\Economic\Client\Endpoint\OrdersEndpointInterface;
use Setono\Economic\Client\Endpoint\ProductsEndpointInterface;
use Setono\Economic\Client\Query\Query;
use Setono\Economic\Exception\InternalServerErrorException;
use Setono\Economic\Exception\NotFoundException;
use Setono\Economic\Exception\UnexpectedStatusCodeException;

interface ClientInterface
{
    /**
     * Returns the last request sent to the API if any requests has been sent
     */
    public function getLastRequest(): ?RequestInterface;

    /**
     * Returns the last response from the API, if any responses has been received
     */
    public function getLastResponse(): ?ResponseInterface;

    /**
     * @throws ClientExceptionInterface if an error happens while processing the request
     * @throws InternalServerErrorException if the server reports an internal server error
     * @throws NotFoundException if the request results in a 404
     * @throws UnexpectedStatusCodeException if the status code is not between 200 and 299, and it's not any of the above
     */
    public function request(RequestInterface $request): ResponseInterface;

    /**
     * @throws ClientExceptionInterface if an error happens while processing the request
     * @throws InternalServerErrorException if the server reports an internal server error
     * @throws NotFoundException if the request results in a 404
     * @throws UnexpectedStatusCodeException if the status code is not between 200 and 299, and it's not any of the above
     */
    public function get(string $uri, Query|array $query = []): ResponseInterface;

    public function invoices(): InvoicesEndpointInterface;

    public function orders(): OrdersEndpointInterface;

    public function products(): ProductsEndpointInterface;
}
