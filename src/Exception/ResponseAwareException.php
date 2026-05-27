<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Psr\Http\Message\ResponseInterface;

abstract class ResponseAwareException extends \RuntimeException implements EconomicException
{
    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    private bool $parsed = false;

    public function __construct(
        private readonly ResponseInterface $response,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        if (null === $message) {
            $message = sprintf('The status code was: %d.', $response->getStatusCode());

            $body = trim((string) $response->getBody());
            if ('' !== $body) {
                $message .= sprintf(' The body was: %s.', $body);
            }

            $message = trim($message);
        }

        parent::__construct($message, 0, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * e-conomic's `errorCode` field if present.
     */
    public function getErrorCode(): ?int
    {
        $body = $this->parseBody();
        if (null === $body) {
            return null;
        }

        $code = $body['errorCode'] ?? null;

        return is_int($code) ? $code : null;
    }

    /**
     * e-conomic's `developerHint` field if present.
     */
    public function getDeveloperHint(): ?string
    {
        $body = $this->parseBody();
        if (null === $body) {
            return null;
        }

        $hint = $body['developerHint'] ?? null;

        return is_string($hint) ? $hint : null;
    }

    /**
     * e-conomic's `logId` — surface this in support tickets.
     */
    public function getLogId(): ?string
    {
        $body = $this->parseBody();
        if (null === $body) {
            return null;
        }

        $logId = $body['logId'] ?? null;

        return is_string($logId) ? $logId : null;
    }

    /**
     * e-conomic's `logTime` parsed as `\DateTimeImmutable`. Returns `null` if absent or malformed.
     */
    public function getLogTime(): ?\DateTimeImmutable
    {
        $body = $this->parseBody();
        if (null === $body) {
            return null;
        }

        $logTime = $body['logTime'] ?? null;
        if (!is_string($logTime) || '' === $logTime) {
            return null;
        }

        try {
            return new \DateTimeImmutable($logTime);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Raw nested validation-error document, verbatim from e-conomic's response.
     *
     * The structure mirrors the request payload — fields that failed validation are replaced by
     * `{errors: [{errorCode, message, value, developerHint}]}` objects; arrays carry per-index
     * error blocks with an `arrayIndex` marker. Returns `[]` if absent.
     *
     * @return array<string, mixed>
     */
    public function getValidationErrors(): array
    {
        $body = $this->parseBody();
        if (null === $body) {
            return [];
        }

        $raw = $body['errors'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        /** @var array<string, mixed> $errors */
        $errors = $raw;

        return $errors;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseBody(): ?array
    {
        if ($this->parsed) {
            return $this->parsedBody;
        }

        $this->parsed = true;

        $body = (string) $this->response->getBody();
        if ('' === $body) {
            return $this->parsedBody = null;
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->parsedBody = null;
        }

        if (!is_array($decoded)) {
            return $this->parsedBody = null;
        }

        /** @var array<string, mixed> $body */
        $body = $decoded;

        return $this->parsedBody = $body;
    }
}
