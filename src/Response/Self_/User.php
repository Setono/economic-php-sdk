<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Self_;

final readonly class User
{
    public function __construct(
        public ?int $agreementNumber = null,
        public ?string $email = null,
        public ?Language $language = null,
        public ?string $loginId = null,
        public ?string $name = null,
    ) {
    }
}
