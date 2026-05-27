<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use Setono\Economic\Response\Self_\Self_;

/**
 * Wraps `GET /self`. v3 will add `putUser()`, `putCompany()`, etc. against the same root.
 *
 * @extends ResourceEndpoint<Self_>
 */
final class SelfEndpoint extends ResourceEndpoint
{
    private ?Self_ $cached = null;

    public function get(): Self_
    {
        return $this->cached ??= $this->getOne();
    }

    protected static function getPath(): string
    {
        return 'self';
    }

    /**
     * @return class-string<Self_>
     */
    protected static function getItemClass(): string
    {
        return Self_::class;
    }
}
