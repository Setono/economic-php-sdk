<?php

declare(strict_types=1);

namespace Setono\Economic\Response\Pagination;

use Webmozart\Assert\Assert;

final class Page
{
    public string $endpoint;

    public int $skipPages;

    public int $pageSize;

    public function __construct(public readonly string $url)
    {
        $parsed = parse_url($url);
        if (!isset($parsed['path'], $parsed['query'])) {
            throw new \InvalidArgumentException(sprintf('Invalid pagination URL: "%s"', $url));
        }

        $this->endpoint = trim($parsed['path'], '/');
        parse_str($parsed['query'], $query);

        // The server is supposed to issue well-formed pagination URLs with numeric `skippages`
        // and `pagesize` query values. Silent `(int)` coercion of garbage would yield `0` and
        // could put the paginator into a pathological loop with no diagnostic. Validate.
        $skipPagesRaw = $query['skippages'] ?? null;
        $pageSizeRaw = $query['pagesize'] ?? null;

        Assert::numeric($skipPagesRaw, sprintf('e-conomic returned a pagination URL with non-numeric `skippages=%s` — refusing to walk: "%s"', var_export($skipPagesRaw, true), $url));
        Assert::numeric($pageSizeRaw, sprintf('e-conomic returned a pagination URL with non-numeric `pagesize=%s` — refusing to walk: "%s"', var_export($pageSizeRaw, true), $url));

        $this->skipPages = (int) $skipPagesRaw;
        $this->pageSize = (int) $pageSizeRaw;

        Assert::greaterThanEq($this->skipPages, 0, sprintf('e-conomic returned a pagination URL with negative skipPages=%d', $this->skipPages));
        Assert::greaterThanEq($this->pageSize, 1, sprintf('e-conomic returned a pagination URL with non-positive pageSize=%d', $this->pageSize));
        Assert::lessThanEq($this->pageSize, 1000, sprintf('e-conomic returned a pagination URL with pageSize=%d exceeding the server maximum of 1000', $this->pageSize));
    }
}
