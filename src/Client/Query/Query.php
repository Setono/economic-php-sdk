<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Query;

class Query implements \Stringable
{
    public function __construct(private readonly array $parameters = [])
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->parameters;
    }

    public function toString(): string
    {
        return http_build_query($this->parameters, '', '&', \PHP_QUERY_RFC3986);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
