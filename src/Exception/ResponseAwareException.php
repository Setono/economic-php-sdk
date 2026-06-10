<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Base class for exceptions that carry an HTTP response from e-conomic.
 *
 * The optional `$body` and `$request` constructor parameters exist to make the lazy-parse
 * getters (`getErrorCode()`, `getLogId()`, `getValidationErrors()`, …) robust against
 * non-seekable PSR-7 streams. When the body has already been read from the response stream
 * — e.g. when the SDK pre-reads it inside `Client::assertStatusCode()` so it can be passed
 * here — supplying `$body` lets `parseBody()` use the cached text instead of re-reading the
 * stream (which would yield `""` on non-seekable implementations and silently degrade every
 * getter to `null` / `[]`).
 *
 * Supplying `$request` embeds a sanitized `[METHOD URL]` segment in the default message.
 * The URL is sanitized — query string and fragment stripped — so secrets a consumer may
 * have passed via `$query` are not leaked into error messages or logs.
 */
abstract class ResponseAwareException extends \RuntimeException implements EconomicException
{
    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    private bool $parsed = false;

    public function __construct(
        private readonly ResponseInterface $response,
        ?string $message = null,
        ?\Throwable $previous = null,
        ?string $body = null,
        ?RequestInterface $request = null,
    ) {
        if (null !== $body) {
            $this->parsed = true;
            $this->parsedBody = self::tryDecode($body);
        }

        if (null === $message) {
            $context = self::buildRequestContext($request);
            $message = sprintf('The status code was: %d.%s', $response->getStatusCode(), $context);

            $bodyText = trim($body ?? (string) $response->getBody());
            if ('' !== $bodyText) {
                $message .= sprintf(' The body was: %s.', $bodyText);
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

        return $this->parsedBody = self::tryDecode((string) $this->response->getBody());
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function tryDecode(string $body): ?array
    {
        if ('' === trim($body)) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $result */
        $result = $decoded;

        return $result;
    }

    /**
     * Build the ` [METHOD URL]` context segment for the default exception message.
     *
     * Strips query string and fragment so any consumer-supplied secrets in query parameters
     * (e.g. a token accidentally placed in `$query`) are not exposed in error messages or logs.
     */
    private static function buildRequestContext(?RequestInterface $request): string
    {
        if (null === $request) {
            return '';
        }

        $sanitizedUri = $request->getUri()->withQuery('')->withFragment('');

        return sprintf(' [%s %s]', $request->getMethod(), (string) $sanitizedUri);
    }
}
