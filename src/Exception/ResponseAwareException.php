<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Psr\Http\Message\ResponseInterface;

abstract class ResponseAwareException extends \RuntimeException
{
    public function __construct(private readonly ResponseInterface $response)
    {
        $message = sprintf('The status code was: %d.', $response->getStatusCode());

        $body = trim((string) $response->getBody());
        if ('' !== $body) {
            $message .= sprintf(' The body was: %s.', $body);
        }

        parent::__construct(trim($message));
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
