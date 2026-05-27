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
#[CoversClass(EconomicException::class)]
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
