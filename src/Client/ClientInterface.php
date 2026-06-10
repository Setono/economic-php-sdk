<?php

declare(strict_types=1);

namespace Setono\Economic\Client;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\Economic\Client\Endpoint\CustomersEndpoint;
use Setono\Economic\Client\Endpoint\InvoicesEndpoint;
use Setono\Economic\Client\Endpoint\OrdersEndpoint;
use Setono\Economic\Client\Endpoint\ProductsEndpoint;
use Setono\Economic\Client\Endpoint\SelfEndpoint;
use Setono\Economic\Exception\EconomicException;
use Setono\Economic\Request\Payload;

interface ClientInterface
{
    /**
     * The last request sent to the API, or `null` if no request has been dispatched yet.
     */
    public ?RequestInterface $lastRequest { get; }

    /**
     * The last response received from the API, or `null` if no response has been received yet.
     */
    public ?ResponseInterface $lastResponse { get; }

    /**
     * @throws ClientExceptionInterface if an error happens while processing the request
     * @throws EconomicException if the response is non-2xx (concrete subtype depends on the status code)
     */
    public function request(RequestInterface $request): ResponseInterface;

    /**
     * GET the given URI and return the decoded JSON body. `$uri` may be either:
     *  - a path relative to the e-conomic base URI (e.g. `"products"`), in which case `$query` is appended.
     *  - a fully-qualified URL pointing at the e-conomic API host (e.g. a `pagination.nextPage.url`),
     *    in which case `$query` MUST be empty.
     *
     * Absolute URLs that don't match the e-conomic base host are rejected — the SDK refuses to leak
     * auth credentials to a different host.
     *
     * For non-JSON endpoints (e.g. PDF or attachment file downloads), build a PSR-7 request and
     * use {@see self::request()} instead.
     *
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     *
     * @throws \Setono\Economic\Exception\InvalidUrlException if `$uri` is absolute and points to a different host
     *     than the base URI, or if absolute `$uri` is combined with a non-empty `$query`
     * @throws ClientExceptionInterface if an error happens while processing the request
     * @throws EconomicException if the response is non-2xx (concrete subtype depends on the status code)
     * @throws \Setono\Economic\Exception\MalformedResponseException if the response body is not valid JSON
     *     or does not decode to an object
     */
    public function get(string $uri, array $query = []): array;

    /**
     * POST the given typed request DTO to `$uri` and return the decoded JSON body.
     *
     * `$body` is normalized to JSON via the SDK's `NormalizerBuilder` (with the `Identifier`
     * transformer and the `Payload` null-skipping transformer registered). The `$body`
     * parameter is typed as `Payload` to enforce wiring — non-Payload objects bypass the
     * null-skipper and would ship `"field": null` keys. For raw-array payloads, use
     * {@see self::request()} directly with a hand-built PSR-7 request.
     *
     * @return array<string, mixed>
     *
     * @throws \Setono\Economic\Exception\InvalidUrlException if `$uri` is absolute and points to a different host than the base URI
     * @throws ClientExceptionInterface if an error happens while processing the request
     * @throws EconomicException if the response is non-2xx (concrete subtype depends on the status code)
     * @throws \Setono\Economic\Exception\MalformedResponseException if the response body is not valid JSON or does not decode to an object
     */
    public function post(string $uri, Payload $body): array;

    public function customers(): CustomersEndpoint;

    public function invoices(): InvoicesEndpoint;

    public function orders(): OrdersEndpoint;

    public function products(): ProductsEndpoint;

    public function self(): SelfEndpoint;
}
