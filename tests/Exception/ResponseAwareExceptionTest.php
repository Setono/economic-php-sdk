<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseAwareException::class)]
final class ResponseAwareExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_the_response(): void
    {
        $response = new Response(404);
        $e = new NotFoundException($response);

        self::assertSame($response, $e->getResponse());
    }

    #[Test]
    public function it_lazy_parses_error_code(): void
    {
        $e = self::exceptionWithBody(['errorCode' => 1100, 'message' => 'whatever']);

        self::assertSame(1100, $e->getErrorCode());
    }

    #[Test]
    public function it_lazy_parses_developer_hint(): void
    {
        $e = self::exceptionWithBody(['developerHint' => 'Try the demo agreement']);

        self::assertSame('Try the demo agreement', $e->getDeveloperHint());
    }

    #[Test]
    public function it_lazy_parses_log_id(): void
    {
        $e = self::exceptionWithBody(['logId' => 'c7ca5bc2-ad2d-4639-93f9-b22ba88c97d7']);

        self::assertSame('c7ca5bc2-ad2d-4639-93f9-b22ba88c97d7', $e->getLogId());
    }

    #[Test]
    public function it_lazy_parses_log_time_as_datetimeimmutable(): void
    {
        $e = self::exceptionWithBody(['logTime' => '2015-03-12T16:44:56']);

        $time = $e->getLogTime();
        self::assertInstanceOf(\DateTimeImmutable::class, $time);
        self::assertSame('2015-03-12T16:44:56+00:00', $time->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function log_time_returns_null_for_malformed_timestamp(): void
    {
        $e = self::exceptionWithBody(['logTime' => 'not-a-date']);

        self::assertNull($e->getLogTime());
    }

    #[Test]
    public function validation_errors_returns_the_raw_nested_document(): void
    {
        $errors = [
            'currency' => ['errors' => [['errorCode' => 'E06000', 'message' => 'currency does not exist.', 'value' => 'ZUL']]],
            'lines' => [
                ['arrayIndex' => 0, 'unitNetPrice' => ['errors' => [['errorCode' => 'E04740', 'message' => 'scale too high', 'value' => 10.12345]]]],
                ['arrayIndex' => 1, 'product' => ['errors' => [['errorCode' => 'E04500', 'message' => 'no identifiers']]]],
            ],
        ];
        $e = self::exceptionWithBody(['errors' => $errors, 'message' => 'Validation error.']);

        self::assertSame($errors, $e->getValidationErrors());
    }

    #[Test]
    public function getters_return_null_or_empty_for_empty_body(): void
    {
        $e = new NotFoundException(new Response(404));

        self::assertNull($e->getErrorCode());
        self::assertNull($e->getDeveloperHint());
        self::assertNull($e->getLogId());
        self::assertNull($e->getLogTime());
        self::assertSame([], $e->getValidationErrors());
    }

    #[Test]
    public function getters_return_null_or_empty_for_malformed_json_body(): void
    {
        $body = Stream::create('this is not json');
        $e = new NotFoundException(new Response(404, [], $body));

        self::assertNull($e->getErrorCode());
        self::assertNull($e->getDeveloperHint());
        self::assertNull($e->getLogId());
        self::assertNull($e->getLogTime());
        self::assertSame([], $e->getValidationErrors());
    }

    #[Test]
    public function getters_return_null_for_wrong_type_values(): void
    {
        $e = self::exceptionWithBody([
            'errorCode' => '1100',
            'developerHint' => 42,
            'logId' => false,
            'logTime' => null,
        ]);

        self::assertNull($e->getErrorCode());
        self::assertNull($e->getDeveloperHint());
        self::assertNull($e->getLogId());
        self::assertNull($e->getLogTime());
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function exceptionWithBody(array $body): ResponseAwareException
    {
        return new NotFoundException(new Response(404, [], (string) json_encode($body)));
    }
}
