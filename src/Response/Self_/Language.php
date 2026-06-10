<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

final readonly class Language
{
    public function __construct(
        public ?int $languageNumber = null,
        public ?string $name = null,
        public ?string $culture = null,
        public ?string $self = null,
    ) {
    }
}
