<?php

declare(strict_types=1);

namespace Setono\Economic\DTO;

final class Page
{
    public string $endpoint;

    public int $skipPages;

    public int $pageSize;

    public function __construct(public readonly string $url)
    {
        $parsed = parse_url($url);
        if (!isset($parsed['path'], $parsed['query'])) {
            throw new \InvalidArgumentException('Invalid URL');
        }

        $this->endpoint = trim($parsed['path'], '/');
        parse_str($parsed['query'], $query);
        $this->skipPages = (int) $query['skippages'];
        $this->pageSize = (int) $query['pagesize'];
    }
}
