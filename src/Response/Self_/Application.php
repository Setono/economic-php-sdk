<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

/**
 * The schema's `requiredRoles` (array of role objects) is deliberately not typed — it stays
 * reachable via `$self->raw['application']['requiredRoles']`.
 */
final readonly class Application
{
    public function __construct(
        public ?int $appNumber = null,
        public ?string $name = null,
        public ?string $appPublicToken = null,
        public ?\DateTimeImmutable $created = null,
        public ?string $self = null,
    ) {
    }
}
