<?php

declare(strict_types=1);

namespace Setono\Economic\Exception;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\Economic\Client\Client;

#[CoversClass(Client::class)]
// EconomicException is deliberately NOT listed: it's an interface, and PHPUnit 11 rejects
// interfaces as coverage targets (they contain no executable code).
#[CoversClass(ResponseAwareException::class)]
#[CoversClass(ClientErrorException::class)]
#[CoversClass(ServerErrorException::class)]
#[CoversClass(UnauthorizedException::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(InternalServerErrorException::class)]
#[CoversClass(NotImplementedException::class)]
#[CoversClass(UnexpectedStatusCodeException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    /**
     * @return iterable<string, array{int, class-string<ResponseAwareException>}>
     */
    public static function statusCodeToExceptionProvider(): iterable
    {
        yield '400 → ValidationException' => [400, ValidationException::class];
        yield '401 → UnauthorizedException' => [401, UnauthorizedException::class];
        yield '403 → ForbiddenException' => [403, ForbiddenException::class];
        yield '404 → NotFoundException' => [404, NotFoundException::class];
        yield '405 → MethodNotAllowedException' => [405, MethodNotAllowedException::class];
        yield '422 → ValidationException' => [422, ValidationException::class];
        yield '500 → InternalServerErrorException' => [500, InternalServerErrorException::class];
        yield '429 → UnexpectedStatusCodeException (e-conomic never returns 429)' => [429, UnexpectedStatusCodeException::class];
        yield '501 → NotImplementedException' => [501, NotImplementedException::class];
        yield '415 → UnexpectedStatusCodeException (no named subclass)' => [415, UnexpectedStatusCodeException::class];
        yield '502 → UnexpectedStatusCodeException' => [502, UnexpectedStatusCodeException::class];
        yield '418 → UnexpectedStatusCodeException' => [418, UnexpectedStatusCodeException::class];
    }

    /**
     * @param class-string<ResponseAwareException> $expected
     */
    #[Test]
    #[DataProvider('statusCodeToExceptionProvider')]
    public function client_dispatches_correct_exception_for_status(int $status, string $expected): void
    {
        $client = new Client('app', 'agreement', httpClient: new FixedStatusHttpClient($status));

        try {
            $client->get('/anything');
            self::fail('expected an exception');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e);
        }
    }

    #[Test]
    public function client_threads_request_context_into_dispatched_exception(): void
    {
        // `Client::assertStatusCode()` threads the outgoing request into the typed exception
        // so the default message includes `[METHOD URL]`. Sanity-checks the wiring end-to-end.
        $client = new Client('app', 'agreement', httpClient: new FixedStatusHttpClient(404));

        try {
            $client->get('products/missing');
            self::fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            self::assertStringContainsString(
                '[GET https://restapi.e-conomic.com/products/missing]',
                $e->getMessage(),
            );
        }
    }

    #[Test]
    public function lazy_parse_works_after_client_dispatch_even_with_pre_consumed_body(): void
    {
        // `Client::assertStatusCode()` pre-reads the response body so the lazy-parse getters
        // survive PSR-18 implementations that return non-seekable streams. This test asserts
        // the wiring: an exception thrown via the dispatch path must expose the parsed
        // errorCode / logId / validationErrors even when the body wasn't re-read.
        $errorDoc = (string) json_encode([
            'errorCode' => 1100,
            'logId' => 'abc-123',
            'errors' => ['currency' => ['errors' => [['errorCode' => 'E06000', 'message' => 'invalid']]]],
        ]);

        $http = new readonly class($errorDoc) implements HttpClientInterface {
            public function __construct(private string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                // Use a Stream and pre-consume it to simulate non-seekable behavior.
                $stream = \Nyholm\Psr7\Stream::create($this->body);
                (string) $stream; // consume

                return new Response(422, ['Content-Type' => 'application/json'], $stream);
            }
        };

        $client = new Client('app', 'agreement', httpClient: $http);

        try {
            $client->get('/anything');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(1100, $e->getErrorCode());
            self::assertSame('abc-123', $e->getLogId());
            self::assertSame(
                ['currency' => ['errors' => [['errorCode' => 'E06000', 'message' => 'invalid']]]],
                $e->getValidationErrors(),
            );
        }
    }

    #[Test]
    public function network_errors_from_the_psr18_layer_propagate_unwrapped_to_the_consumer(): void
    {
        // The SDK does NOT wrap PSR-18 NetworkExceptionInterface / ClientExceptionInterface.
        // Consumers needing retry behavior wrap their PSR-18 client with retry middleware; the
        // SDK refuses to take on transient-error policy. This locks that contract.
        $networkError = new class('connection refused') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new \LogicException('not used in this test');
            }
        };

        $http = new readonly class($networkError) implements HttpClientInterface {
            public function __construct(private \Psr\Http\Client\NetworkExceptionInterface $error)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw $this->error;
            }
        };

        $client = new Client('app', 'agreement', httpClient: $http);

        try {
            $client->get('/anything');
            self::fail('expected NetworkExceptionInterface to propagate');
        } catch (\Psr\Http\Client\NetworkExceptionInterface $e) {
            self::assertSame($networkError, $e, 'the same instance must propagate unwrapped');
            // PHPStan reads the anonymous class declaration and knows it does NOT implement
            // EconomicException, so the assertion below is omitted (it would be tautological).
            // The behavioral test above (`catch (NetworkExceptionInterface)`) already locks the
            // contract: network errors propagate as-is.
        }
    }

    #[Test]
    public function every_concrete_exception_implements_economic_exception(): void
    {
        $exceptionDir = __DIR__ . '/../../src/Exception';
        $files = glob($exceptionDir . '/*.php');
        self::assertNotFalse($files);

        foreach ($files as $file) {
            /** @var class-string $className */
            $className = 'Setono\\Economic\\Exception\\' . basename($file, '.php');
            if ($className === EconomicException::class) {
                continue;
            }

            $reflection = new \ReflectionClass($className);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            self::assertTrue(
                $reflection->implementsInterface(EconomicException::class),
                $className . ' must implement EconomicException',
            );
        }
    }
}

final readonly class FixedStatusHttpClient implements HttpClientInterface
{
    public function __construct(private int $status)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response($this->status);
    }
}
