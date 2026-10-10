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
 * The fields that say where a body ends are checked before anything is sent. A Content-Length
 * that was no number, two of them or a list of them was treated as no length and sent as it
 * was, with a body of any length behind it; a Transfer-Encoding of the response's own went
 * out next to raw bytes. A Content-Length above 0 in front of an empty body promised bytes
 * that never came, where no byte was sent.
 */
#[RunTestsInSeparateProcesses]
class EmitFramingTest extends TestCase
{
    private const LENGTH_REFUSED = 'Response Content-Length must be exactly one value of digits';
    private const CODING_REFUSED = 'Response must not carry a Transfer-Encoding: the emitter applies no transfer coding, the body goes out as it is';
    private const EMPTY_REFUSED = 'Response body is empty, but its Content-Length is not (an answer to HEAD is emitted with withBody false)';

    /**
     * A response with exactly these header fields — no PSR-7 object folds or checks them —
     * behind a Location: had a field gone out before the refusal, PHP's status would be a 302.
     *
     * @param array<string, list<string>> $fields
     */
    private static function respondingWith(int $status, array $fields, string $body = 'abcdef'): ResponseInterface
    {
        // @phpstan-ignore class.extendsFinalByPhpDoc (a response that misbehaves on purpose)
        return new class ($status, $fields, $body) extends Psr7Response {
            /** @param array<string, list<string>> $fields */
            public function __construct(int $status, private readonly array $fields, string $body)
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
     * @return array{string, ?RouterException}
     */
    private function emit(ResponseInterface $response, bool $withBody = true): array
    {
        $level = ob_get_level();
        ob_start();

        try {
            Router::create()->emit($response, $withBody);

            return [(string) ob_get_contents(), null];
        } catch (RouterException $e) {
            return [(string) ob_get_contents(), $e];
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * @return array<string, array{0: array<string, list<string>>, 1: string}>
     */
    public static function framingThatSaysNothingToRelyOn(): array
    {
        return [
            'a length that is no number' => [['Content-Length' => ['abc']], self::LENGTH_REFUSED],
            'a list of lengths in one line' => [['Content-Length' => ['3, 3']], self::LENGTH_REFUSED],
            'the same length twice' => [['Content-Length' => ['3', '3']], self::LENGTH_REFUSED],
            'two lengths that differ' => [['Content-Length' => ['3', '5']], self::LENGTH_REFUSED],
            'one length under two spellings of its name' => [['Content-Length' => ['6'], 'content-length' => ['6']], self::LENGTH_REFUSED],
            'a length with a sign' => [['Content-Length' => ['+6']], self::LENGTH_REFUSED],
            'a negative length' => [['Content-Length' => ['-1']], self::LENGTH_REFUSED],
            'a length with a blank' => [['Content-Length' => [' 6']], self::LENGTH_REFUSED],
            'an empty length' => [['Content-Length' => ['']], self::LENGTH_REFUSED],
            'a length PHP cannot count to' => [['Content-Length' => ['99999999999999999999']], self::LENGTH_REFUSED],
            'a length of 19 digits' => [['Content-Length' => ['1000000000000000000']], self::LENGTH_REFUSED],
            'a transfer coding' => [['Transfer-Encoding' => ['chunked']], self::CODING_REFUSED],
            'a transfer coding and a length' => [['Transfer-Encoding' => ['chunked'], 'Content-Length' => ['6']], self::CODING_REFUSED],
            'a transfer coding in lower case' => [['transfer-encoding' => ['gzip']], self::CODING_REFUSED],
        ];
    }

    /**
     * @param array<string, list<string>> $fields
     */
    #[DataProvider('framingThatSaysNothingToRelyOn')]
    public function testFramingThatSaysNothingToRelyOnIsRefusedBeforeAnythingIsSent(array $fields, string $message): void
    {
        [$sent, $thrown] = $this->emit(self::respondingWith(403, $fields));

        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame($message, $thrown->getMessage());
        // Nothing went out: no status (the Location in front would have made it a 302), no body
        $this->assertSame('', $sent);
        $this->assertFalse(http_response_code());
    }

    /**
     * @param array<string, list<string>> $fields
     */
    #[DataProvider('framingThatSaysNothingToRelyOn')]
    public function testFramingThatSaysNothingToRelyOnIsRefusedForHeadAsWell(array $fields, string $message): void
    {
        [$sent, $thrown] = $this->emit(self::respondingWith(403, $fields), withBody: false);

        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame($message, $thrown->getMessage());
        $this->assertFalse(http_response_code());
    }

    public function testLengthWithZerosInFrontIsOneValueOfDigits(): void
    {
        $this->assertSame(['abcdef', null], $this->emit(new Psr7Response(200, ['Content-Length' => '0006'], 'abcdef')));
    }

    public function testRunAnswersA500ForAResponseWhoseFramingSaysNothingToRelyOn(): void
    {
        foreach ([[['Content-Length' => ['3', '5']], self::LENGTH_REFUSED], [['Transfer-Encoding' => ['chunked']], self::CODING_REFUSED]] as [$fields, $message]) {
            [$sent, $reports] = $this->runWith(self::respondingWith(403, $fields));

            $this->assertSame('Internal Server Error', $sent);
            $this->assertSame(500, http_response_code());
            $this->assertSame([$message . ' | 500'], $reports);
        }
    }

    public function testLengthAbove0InFrontOfABodyThatIsEmptyIsRefusedBeforeAnythingIsSent(): void
    {
        [$sent, $thrown] = $this->emit(self::respondingWith(200, ['Content-Length' => ['10']], ''));

        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame(self::EMPTY_REFUSED, $thrown->getMessage());
        $this->assertSame('Content-Length 10', $thrown->getDebugMessage());
        $this->assertSame('', $sent);
        $this->assertFalse(http_response_code());
    }

    public function testBodyOfUnknownSizeThatSendsNoByteEndsShortOfItsLength(): void
    {
        // Its size is not known before it is sent: held to the length while it is
        $body = $this->createStub(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('getSize')->willReturn(null);
        $body->method('eof')->willReturn(true);

        [$sent, $thrown] = $this->emit(new Psr7Response(200, ['Content-Length' => '10'], $body));

        $this->assertInstanceOf(RouterException::class, $thrown);
        $this->assertSame('Response body ended before its Content-Length', $thrown->getMessage());
        $this->assertSame('0 of 10 bytes sent', $thrown->getDebugMessage());
        $this->assertSame('', $sent);
    }

    public function testEmptyBodyGoesOutWithALengthOf0OrWithoutABodyForHead(): void
    {
        $this->assertSame(['', null], $this->emit(new Psr7Response(200, ['Content-Length' => '0'], '')));
        $this->assertSame(['', null], $this->emit(new Psr7Response(200, ['Content-Length' => '10'], ''), withBody: false));
    }

    public function testRunAnswersA500ForALengthAbove0InFrontOfAnEmptyBody(): void
    {
        [$sent, $reports] = $this->runWith(new Psr7Response(200, ['Content-Length' => '10'], ''));

        $this->assertSame('Internal Server Error', $sent);
        $this->assertSame(500, http_response_code());
        $this->assertSame([self::EMPTY_REFUSED . ' | 500'], $reports);
    }

    /**
     * What run() sent for a route that answers with $response, and what the error hook
     * heard (message and status).
     *
     * @return array{string, list<string>}
     */
    private function runWith(ResponseInterface $response): array
    {
        $GLOBALS['emit_framing_response'] = $response;
        $routes = sys_get_temp_dir() . '/router_emit_framing_' . uniqid() . '.php';
        file_put_contents($routes, '<?php return function ($r) { $r->get("/with", fn () => $GLOBALS["emit_framing_response"]); };');
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
            unset($GLOBALS['emit_framing_response']);
        }

        return [$sent, $reports];
    }
}
