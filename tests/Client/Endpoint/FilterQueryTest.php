<?php

declare(strict_types=1);

namespace Setono\Economic\Client\Endpoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Setono\Economic\Client\Client;
use Setono\Economic\Request\CollectionRequestOptions;
use Setono\Economic\Request\Filter;
use Setono\Economic\TestDouble\ScriptedHttpClient;

/**
 * Proves the layering contract: {@see Filter} emits the UNENCODED e-conomic expression
 * and the client applies RFC 3986 query encoding when the URL is built.
 */
#[CoversClass(Filter::class)]
#[CoversClass(CollectionRequestOptions::class)]
final class FilterQueryTest extends TestCase
{
    #[Test]
    public function a_built_filter_is_rfc3986_encoded_in_the_request_url(): void
    {
        $expectedUrl = 'https://restapi.e-conomic.com/products?skippages=0&pagesize=20&filter=lastUpdated%24gte%3A2026-01-01T00%3A30%3A00Z';

        // ScriptedHttpClient throws on any URL it was not scripted with, so the
        // scripting itself asserts the exact encoded URL.
        $http = new ScriptedHttpClient()->on($expectedUrl, self::emptyPageJson());

        $client = new Client('app', 'agreement', httpClient: $http);

        $since = new \DateTimeImmutable('2026-01-01 01:30:00', new \DateTimeZone('Europe/Copenhagen'));
        $client->products()->getPage(new CollectionRequestOptions(filter: Filter::gte('lastUpdated', $since)));

        self::assertCount(1, $http->sentRequests);
        self::assertSame($expectedUrl, (string) $http->sentRequests[0]->getUri());
    }

    private static function emptyPageJson(): string
    {
        $envelope = [
            'collection' => [],
            'pagination' => [
                'maxPageSizeAllowed' => 1000,
                'skipPages' => 0,
                'pageSize' => 20,
                'results' => 0,
                'resultsWithoutFilter' => 0,
                'firstPage' => ['url' => 'https://restapi.e-conomic.com/x?skippages=0&pagesize=20'],
                'lastPage' => ['url' => 'https://restapi.e-conomic.com/x?skippages=0&pagesize=20'],
                'nextPage' => null,
            ],
        ];

        return (string) json_encode($envelope);
    }
}
