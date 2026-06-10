<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Document;

/**
 * `$download` is the URL of the document's PDF representation. The endpoint returns binary
 * data, not JSON — fetch it through `Client::request()` rather than `Client::get()`.
 */
final readonly class Pdf
{
    public function __construct(
        public ?string $download = null,
    ) {
    }
}
