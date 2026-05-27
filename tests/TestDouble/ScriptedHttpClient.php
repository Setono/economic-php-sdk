<?php

declare(strict_types=1);

namespace Setono\Economic\TestDouble;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-process HTTP fake. Returns scripted responses keyed by the request URI.
 *
 * Records every dispatched request in $sentRequests so assertions can run after the fact.
 * This is a fake, not a mock — it's the right tool for "given this URL was hit, return that
 * pre-baked response," which is what every pagination test needs.
 */
final class ScriptedHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sentRequests = [];

    /** @var array<string, ResponseInterface> */
    private array $scripted = [];

    public function on(string $uri, ResponseInterface|string $response, int $status = 200): self
    {
        if (is_string($response)) {
            $response = new Response($status, ['Content-Type' => 'application/json'], $response);
        }
        $this->scripted[$uri] = $response;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sentRequests[] = $request;
        $uri = (string) $request->getUri();

        if (!isset($this->scripted[$uri])) {
            throw new \RuntimeException(sprintf(
                'ScriptedHttpClient has no script for "%s". Scripted: %s',
                $uri,
                implode(', ', array_keys($this->scripted)),
            ));
        }

        return $this->scripted[$uri];
    }
}
