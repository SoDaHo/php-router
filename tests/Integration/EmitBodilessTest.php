<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Integration;

use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Router;

/**
 * A 1xx, 204, 205 or 304 has no content (RFC 9110, 6.4.1 and 15.3.6). The emitter read and
 * sent their bodies all the same — bytes a kept-alive client reads as the start of the next
 * response. Their body is not read now, not even rewound: a size of 0 goes out without one,
 * any other size (or none known) is refused before anything is sent, and so is a
 * Content-Length the status rules out.
 */
#[RunTestsInSeparateProcesses]
class EmitBodilessTest extends TestCase
{
    private const BODY_REFUSED = 'Response with status 1xx, 204, 205 or 304 must not have a body: its body is not empty, or of a size it does not know';
    private const LENGTH_REFUSED = 'Response with status 1xx or 204 must not carry a Content-Length';
    private const RESET_REFUSED = 'Response with status 205 has no content: its Content-Length can only be 0';

    /**
     * A body that says its size and throws at every touch of its content — rewind(),
     * seek(), read(), eof(), getContents(), __toString(): what the emitter must not do.
     */
    private function untouchable(?int $size): StreamInterface
    {
        $body = $this->createStub(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->method('getSize')->willReturn($size);

        foreach (['rewind', 'seek', 'read', 'eof', 'getContents', '__toString'] as $method) {
            $body->method($method)->willThrowException(new \LogicException($method . '() was called on a body that does not go out'));
        }

        return $body;
    }

    /**
     * A response with exactly these header fields behind a Location: had a field gone out
     * before the refusal, PHP's status would be set.
     *
     * @param array<string, list<string>> $fields
     */
    private static function respondingWith(int $status, array $fields, StreamInterface|string $body): ResponseInterface
    {
        // @phpstan-ignore class.extendsFinalByPhpDoc (a response that misbehaves on purpose)
        return new class ($status, $fields, $body) extends Psr7Response {
            /** @param array<string, list<string>> $fields */
            public function __construct(int $status, private readonly array $fields, StreamInterface|string $body)
            {
                parent::__construct($status, [], $body);
            }

            public function getHeaders(): array
            {
                return ['Location' => ['/there'], ...$this->fields];
            }
        };
    }

    /**
     * What emit() sent before it threw, and what it threw.
     *
     * @return array{string, ?\Throwable}
     */
    private function emit(ResponseInterface $response, bool $withBody = true): array
    {
        $level = ob_get_level();
        ob_start();

        try {
            Router::create()->emit($response, $withBody);

            return [(string) ob_get_contents(), null];
        } catch (\Throwable $e) {
            return [(string) ob_get_contents(), $e];
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * @return array<string, array{0: int, 1: array<string, string>}>
     */
    public static function responsesWithoutContent(): array
    {
        return [
            'status 100' => [100, []],
            'status 103' => [103, []],
            'status 199' => [199, []],
            'status 204' => [204, []],
            'status 205' => [205, []],
            '205 with a length of 0' => [205, ['Content-Length' => '0']],
            'status 304' => [304, []],
            // The length of the representation the 304 stands for (RFC 9110, 8.6)
            '304 with the length of the representation' => [304, ['Content-Length' => '123']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('responsesWithoutContent')]
    public function testBodyOfSize0GoesOutWithoutBeingRead(int $status, array $headers): void
    {
        [$sent, $thrown] = $this->emit(new Psr7Response($status, $headers, $this->untouchable(0)));

        $this->assertNull($thrown, $thrown?->getMessage() ?? '');
        $this->assertSame('', $sent);
        $this->assertSame($status, http_response_code());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function statusesWithoutContent(): array
    {
        return ['status 100' => [100], 'status 103' => [103], 'status 204' => [204], 'status 205' => [205], 'status 304' => [304]];
    }

    #[DataProvider('statusesWithoutContent')]
    public function testBodyThatIsNotEmptyIsRefusedBeforeAnythingIsSent(int $status): void
    {
        [$sent, $thrown] = $this->emit(self::respondingWith($status, [], 'abc'));

        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame(self::BODY_REFUSED, $thrown->getMessage());
        $this->assertSame(sprintf('status %d, body size 3', $status), $thrown->getDebugMessage());
        $this->assertSame('', $sent);
        $this->assertFalse(http_response_code());
    }

    #[DataProvider('statusesWithoutContent')]
    public function testBodyThatDoesNotKnowItsSizeIsRefusedWithoutBeingRead(int $status): void
    {
        [$sent, $thrown] = $this->emit(self::respondingWith($status, [], $this->untouchable(null)));

        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame(self::BODY_REFUSED, $thrown->getMessage());
        $this->assertSame(sprintf('status %d, body size unknown', $status), $thrown->getDebugMessage());
        $this->assertSame('', $sent);
        $this->assertFalse(http_response_code());
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function lengthsTheStatusRulesOut(): array
    {
        return [
            '1xx with a length' => [103, '0', self::LENGTH_REFUSED],
            '204 with a length of 0' => [204, '0', self::LENGTH_REFUSED],
            '204 with a length' => [204, '3', self::LENGTH_REFUSED],
            '205 with a length above 0' => [205, '3', self::RESET_REFUSED],
        ];
    }

    #[DataProvider('lengthsTheStatusRulesOut')]
    public function testLengthTheStatusRulesOutIsRefusedBeforeAnythingIsSent(int $status, string $length, string $message): void
    {
        foreach ([true, false] as $withBody) {
            [$sent, $thrown] = $this->emit(self::respondingWith($status, ['Content-Length' => [$length]], $this->untouchable(0)), $withBody);

            $this->assertInstanceOf(RouterException::class, $thrown);
            $this->assertSame($message, $thrown->getMessage());
            $this->assertSame('', $sent);
            $this->assertFalse(http_response_code());
        }
    }

    public function testAnswerToHeadIsNotAskedForItsBody(): void
    {
        // withBody false: the body is not looked at, whatever the status
        [$sent, $thrown] = $this->emit(new Psr7Response(304, ['Content-Length' => '123'], $this->untouchable(null)), withBody: false);

        $this->assertNull($thrown);
        $this->assertSame('', $sent);
        $this->assertSame(304, http_response_code());
    }

    public function testRunAnswersA500ForA204WithABody(): void
    {
        $GLOBALS['emit_bodiless_response'] = new Psr7Response(204, [], 'abc');
        $routes = sys_get_temp_dir() . '/router_emit_bodiless_' . uniqid() . '.php';
        file_put_contents($routes, '<?php return function ($r) { $r->get("/with", fn () => $GLOBALS["emit_bodiless_response"]); };');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/with';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $reports = [];

        ob_start();

        try {
            Router::create()
                ->loadRoutes($routes)
                ->on('error', function (array $data) use (&$reports): void {
                    $reports[] = $data['exception']->getMessage() . ' | ' . $data['status'];
                })
                ->run();
        } finally {
            $sent = (string) ob_get_clean();
            unlink($routes);
            unset($GLOBALS['emit_bodiless_response']);
        }

        $this->assertSame('Internal Server Error', $sent);
        $this->assertSame(500, http_response_code());
        $this->assertSame([self::BODY_REFUSED . ' | 500'], $reports);
    }
}
