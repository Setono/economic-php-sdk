<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Document;

final readonly class Notes
{
    public function __construct(
        public ?string $heading = null,
        public ?string $textLine1 = null,
        public ?string $textLine2 = null,
    ) {
    }
}
