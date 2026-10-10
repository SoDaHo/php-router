<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Response;

class ResponseFileTest extends TestCase
{
    private string $path;

    /**
     * Deterministic payload whose every byte depends on its position: blocks of SHA-256 of
     * the block number. A periodic fixture hides offset bugs — the earlier '(i * 37 + 11) %
     * 256' repeated itself every 256 bytes, and a range off by 256 read the same bytes.
     */
    private string $payload;

    protected function setUp(): void
    {
        $this->payload = '';
        for ($i = 0; strlen($this->payload) < 1000; $i++) {
            $this->payload .= hash('sha256', (string) $i, true);
        }
        $this->payload = substr($this->payload, 0, 1000);

        $this->path = sys_get_temp_dir() . '/response_file_test_' . uniqid() . '.bin';
        file_put_contents($this->path, $this->payload);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    public function testFullFileResponse(): void
    {
        $r = Response::file($this->path, 'report.pdf', 'application/pdf');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('application/pdf', $r->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="report.pdf"', $r->getHeaderLine('Content-Disposition'));
        $this->assertSame('1000', $r->getHeaderLine('Content-Length'));
        $this->assertSame('bytes', $r->getHeaderLine('Accept-Ranges'));
        $this->assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame($this->payload, (string) $r->getBody());
    }

    public function testFilenameDefaultsToBasename(): void
    {
        $r = Response::file($this->path);

        $this->assertStringContainsString(basename($this->path), $r->getHeaderLine('Content-Disposition'));
    }

    public function testFilenameQuotesAreEscapedAndSeparatorsRemoved(): void
    {
        $r = Response::file($this->path, 'we"ird\\name.txt');

        $this->assertSame('attachment; filename="we\\"ird_name.txt"', $r->getHeaderLine('Content-Disposition'));
    }

    public function testControlCharactersInFilenameDoNotBreakTheHeader(): void
    {
        // A raw CRLF used to make PSR-7 reject the header value: the download died with an
        // uncaught InvalidArgumentException instead of being served (upload names are user input).
        $r = Response::file($this->path, "Befund\r\nX-Injected: pwned.pdf");

        $this->assertSame('attachment; filename="BefundX-Injected: pwned.pdf"', $r->getHeaderLine('Content-Disposition'));
        $this->assertFalse($r->hasHeader('X-Injected'));
    }

    public function testUnicodeFilenameGetsRfc5987Form(): void
    {
        $r = Response::file($this->path, 'Röntgen Müller.pdf');
        $disposition = $r->getHeaderLine('Content-Disposition');

        $this->assertStringContainsString('filename="R_ntgen M_ller.pdf"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''R%C3%B6ntgen%20M%C3%BCller.pdf", $disposition);
    }

    public function testBidiOverrideIsStrippedFromFilename(): void
    {
        // U+202E (right-to-left override) disguises "…fdp.exe" as "…exe.pdf" in the download dialog.
        $r = Response::file($this->path, "Rechnung\u{202E}fdp.exe");

        $this->assertStringNotContainsString("\u{202E}", $r->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('Rechnungfdp.exe', rawurldecode($r->getHeaderLine('Content-Disposition')));
    }

    public function testBidiMarksAreStrippedFromFilename(): void
    {
        // Overrides were removed, the plain marks were not: LRM, RLM and the Arabic letter
        // mark reorder the visible name just as well and travelled on inside filename*.
        foreach (["\u{200E}" => 'LRM', "\u{200F}" => 'RLM', "\u{061C}" => 'ALM', "\u{2066}" => 'LRI', "\u{2069}" => 'PDI'] as $mark => $label) {
            $header = Response::file($this->path, "Rechnung{$mark}fdp.exe")->getHeaderLine('Content-Disposition');

            $this->assertSame('attachment; filename="Rechnungfdp.exe"', $header, $label);
        }

        // Characters next to the stripped ranges are ordinary text
        $header = Response::download('x', "a\u{200D}b\u{061B}c.txt")->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString(rawurlencode("a\u{200D}b\u{061B}c.txt"), $header);
    }

    public function testInlineDisposition(): void
    {
        $r = Response::file($this->path, 'clip.mp4', 'video/mp4', true);

        $this->assertStringStartsWith('inline;', $r->getHeaderLine('Content-Disposition'));
    }

    public function testExplicitRangeReturnsExactlyThoseBytes(): void
    {
        $r = Response::file($this->path, 'clip.mp4', 'video/mp4', true, 'bytes=100-199');

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 100-199/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame('100', $r->getHeaderLine('Content-Length'));
        $this->assertSame(substr($this->payload, 100, 100), (string) $r->getBody());
    }

    public function testOpenEndedRangeRunsToEndOfFile(): void
    {
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=900-');

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 900-999/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame(substr($this->payload, 900), (string) $r->getBody());
    }

    public function testSuffixRangeReturnsLastBytes(): void
    {
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=-50');

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 950-999/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame(substr($this->payload, 950), (string) $r->getBody());
    }

    public function testRangeEndBeyondFileIsClamped(): void
    {
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=990-99999');

        $this->assertSame('bytes 990-999/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame(substr($this->payload, 990), (string) $r->getBody());
    }

    public function testMaxChunkCapsTheServedRange(): void
    {
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=0-', 64);

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 0-63/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame('64', $r->getHeaderLine('Content-Length'));
        $this->assertSame(substr($this->payload, 0, 64), (string) $r->getBody());
    }

    public function testMaxChunkTrimsAtTheEndOfASuffixRange(): void
    {
        // Documented behaviour: the cap always cuts at the END of the requested range.
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=-5', 2);

        $this->assertSame('bytes 995-996/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame(substr($this->payload, 995, 2), (string) $r->getBody());
    }

    public function testMaxChunkBelowOneIsRejected(): void
    {
        try {
            Response::file($this->path, null, 'application/octet-stream', false, 'bytes=0-', 0);
            $this->fail('The response was built');
        } catch (RouterException $e) {
            $this->assertSame('maxChunk must be at least 1', $e->getMessage());
        }
    }

    public function testUnsatisfiableRangeReturns416(): void
    {
        $r = Response::file($this->path, 'clip.mp4', 'video/mp4', false, 'bytes=5000-6000');

        $this->assertSame(416, $r->getStatusCode());
        $this->assertSame('bytes */1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame('0', $r->getHeaderLine('Content-Length'));
        $this->assertSame('', (string) $r->getBody());
        // Headers must not describe a body that is not there.
        $this->assertFalse($r->hasHeader('Content-Type'));
        $this->assertFalse($r->hasHeader('Content-Disposition'));
    }

    public function testRangeOnEmptyFileIsUnsatisfiable(): void
    {
        $empty = sys_get_temp_dir() . '/response_file_empty_' . uniqid();
        file_put_contents($empty, '');

        $r = Response::file($empty, null, 'application/octet-stream', false, 'bytes=0-');

        $this->assertSame(416, $r->getStatusCode());
        @unlink($empty);
    }

    public function testMalformedRangeFallsBackToTheFullResponse(): void
    {
        foreach (['items=0-10', 'bytes=abc', 'bytes=-', 'bytes=0-1,20-30'] as $bad) {
            $r = Response::file($this->path, null, 'application/octet-stream', false, $bad);

            $this->assertSame(200, $r->getStatusCode(), $bad);
            $this->assertFalse($r->hasHeader('Content-Range'), $bad);
            $this->assertSame('1000', $r->getHeaderLine('Content-Length'), $bad);
            $this->assertSame($this->payload, (string) $r->getBody(), $bad);
        }
    }

    public function testFilenameThatSanitizesToNothingFallsBackToDownload(): void
    {
        $r = Response::file($this->path, "\r\n\x00");

        $this->assertSame('attachment; filename="download"', $r->getHeaderLine('Content-Disposition'));
    }

    public function testInvalidUtf8FilenameStillProducesAUsableHeader(): void
    {
        // latin1-encoded umlaut: the /u-pattern bails out, the byte-wise fallback takes over.
        $r = Response::file($this->path, "Gr\xFCn.pdf");
        $disposition = $r->getHeaderLine('Content-Disposition');

        $this->assertStringContainsString('filename="Gr_n.pdf"', $disposition);
        // RFC 8187: no extended form for bytes that are not valid UTF-8 — declaring UTF-8
        // and then sending latin1 octets would be a lie.
        $this->assertStringNotContainsString('filename*', $disposition);
    }

    public function testZeroLengthSuffixRangeIsUnsatisfiable(): void
    {
        // 'bytes=-0' asks for the last zero bytes — RFC 9110 says that is unsatisfiable.
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=-0');

        $this->assertSame(416, $r->getStatusCode());
    }

    public function testTruncationRepairsACutMultiByteSequence(): void
    {
        // 4-byte emoji at an offset where the 200-byte cut lands INSIDE a codepoint: the
        // repair loop has to peel three bytes. Without it (or with fewer iterations) the stem
        // stays invalid UTF-8, the RFC 8187 gate blocks, and the client only gets a name made
        // of underscores. The umlaut test alone never reaches this — it cuts on a boundary.
        $r = Response::file($this->path, 'x' . str_repeat("\u{1F600}", 100) . '.pdf');
        $disposition = $r->getHeaderLine('Content-Disposition');

        $this->assertStringContainsString("filename*=UTF-8''", $disposition, 'extended form must survive truncation');
        $encoded = explode("UTF-8''", $disposition)[1] ?? '';
        $this->assertSame(1, preg_match('//u', rawurldecode($encoded)), 'truncated name must stay valid UTF-8');
    }

    public function testTruncationRepairsAThreeByteSequenceToo(): void
    {
        $r = Response::file($this->path, 'xx' . str_repeat("\u{4E2D}", 100) . '.pdf');
        $encoded = explode("UTF-8''", $r->getHeaderLine('Content-Disposition'))[1] ?? '';

        $this->assertNotSame('', $encoded);
        $this->assertSame(1, preg_match('//u', rawurldecode($encoded)));
    }

    public function testFilenameNeverExceedsTheCapEvenWithAnEarlyDot(): void
    {
        // A dot near the front must not turn the whole rest into an "extension" and smuggle
        // a 400-byte value into the header.
        $r = Response::file($this->path, 'a.' . str_repeat('b', 400));
        preg_match('/filename="([^"]*)"/', $r->getHeaderLine('Content-Disposition'), $m);

        $this->assertLessThanOrEqual(200, strlen($m[1] ?? ''));
    }

    public function testWhitespaceOnlyFilenameFallsBackToDownload(): void
    {
        $r = Response::file($this->path, "\u{202E}   \u{202E}");

        $this->assertSame('attachment; filename="download"', $r->getHeaderLine('Content-Disposition'));
    }

    public function testMaxChunkLargerThanTheRangeLeavesItAlone(): void
    {
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=10-19', 5000);

        $this->assertSame('bytes 10-19/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame(substr($this->payload, 10, 10), (string) $r->getBody());
    }

    public function testMaxChunkNeverRunsPastEndOfFile(): void
    {
        $r = Response::file($this->path, null, 'application/octet-stream', false, 'bytes=950-', 5000);

        $this->assertSame('bytes 950-999/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame('50', $r->getHeaderLine('Content-Length'));
        $this->assertSame(50, strlen((string) $r->getBody()));
    }

    public function testReversedRangeIsUnsatisfiable(): void
    {
        // Without the start > end guard this produced a 206 with Content-Length: -1.
        foreach (['bytes=5-3', 'bytes=100-99'] as $range) {
            $r = Response::file($this->path, null, 'application/octet-stream', false, $range);

            $this->assertSame(416, $r->getStatusCode(), $range);
        }
    }

    public function testMissingFileThrows(): void
    {
        try {
            Response::file($this->path . '_missing');
            $this->fail('The response was built');
        } catch (RouterException $e) {
            // The path is not part of the message
            $this->assertSame('Cannot read file', $e->getMessage());
            $this->assertSame($this->path . '_missing', $e->getDebugMessage());
        }
    }

    public function testDirectoryPathThrows(): void
    {
        // filesize() answers for directories — without an is_file() guard this produced a
        // 200 with Content-Length and then blew up mid-body, after the headers were out.
        $this->expectException(RouterException::class);
        Response::file(sys_get_temp_dir());
    }

    public function testUnreadableFileThrows(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses file permissions');
        }

        $locked = sys_get_temp_dir() . '/response_file_locked_' . uniqid();
        file_put_contents($locked, 'secret');
        chmod($locked, 0o000);

        try {
            // Range path on purpose: an unsatisfiable range never constructs a FileStream,
            // so only the is_readable() guard can produce the exception. Without the guard
            // this returns a 416 and the test would pass on broken code.
            $this->expectException(RouterException::class);
            Response::file($locked, null, 'application/octet-stream', false, 'bytes=5000-6000');
        } finally {
            chmod($locked, 0o644);
            @unlink($locked);
        }
    }

    public function testDownloadCanBeInline(): void
    {
        $attachment = Response::download('PNG', 'avatar.png', 'image/png');
        $inline = Response::download('PNG', 'avatar.png', 'image/png', inline: true);

        $this->assertSame('attachment; filename="avatar.png"', $attachment->getHeaderLine('Content-Disposition'));
        $this->assertSame('inline; filename="avatar.png"', $inline->getHeaderLine('Content-Disposition'));
        $this->assertSame($attachment->getHeaderLine('Content-Length'), $inline->getHeaderLine('Content-Length'));
        $this->assertSame('PNG', (string) $inline->getBody());
    }

    public function testDownloadSanitizesItsFilenameToo(): void
    {
        // download() takes the same untrusted names; a raw CRLF used to kill the response.
        $r = Response::download('data', "Befund\r\nX-Injected: pwned.pdf");

        $this->assertSame('attachment; filename="BefundX-Injected: pwned.pdf"', $r->getHeaderLine('Content-Disposition'));
        $this->assertFalse($r->hasHeader('X-Injected'));
    }

    public function testDownloadGetsTheRfc5987FormForUmlauts(): void
    {
        $r = Response::download('data', 'Röntgen.pdf');

        $this->assertStringContainsString("filename*=UTF-8''R%C3%B6ntgen.pdf", $r->getHeaderLine('Content-Disposition'));
    }

    public function testBidiOverrideCannotRideInOnInvalidUtf8(): void
    {
        // A single invalid byte disables the /u-based bidi strip. The extended form must
        // then be omitted, otherwise the override reaches the client after all.
        $r = Response::file($this->path, "Rechnung\xF6\u{202E}fdp.exe");
        $disposition = $r->getHeaderLine('Content-Disposition');

        $this->assertStringNotContainsString('filename*', $disposition);
        $this->assertStringNotContainsString("\u{202E}", $disposition);
    }

    public function testDotOnlyFilenameFallsBackToDownload(): void
    {
        $r = Response::file($this->path, '..');

        $this->assertSame('attachment; filename="download"', $r->getHeaderLine('Content-Disposition'));
    }

    public function testTruncationDoesNotSmuggleBidiOverridesBackIn(): void
    {
        // The invalid byte sits BEYOND the 200-byte cap, the override well inside it. Cutting
        // the tail turns the name into valid UTF-8 — override included — so a truncation that
        // runs AFTER the bidi strip hands the RFC 8187 gate something it happily lets through.
        $name = "Rechnung\u{202E}fdp.exe" . str_repeat('a', 190) . "\xFF";
        $r = Response::file($this->path, $name);
        $disposition = $r->getHeaderLine('Content-Disposition');

        $this->assertStringNotContainsString("\u{202E}", rawurldecode($disposition));
        $this->assertStringNotContainsString('%E2%80%AE', $disposition);
    }

    public function testTruncationKeepsTheExtension(): void
    {
        $r = Response::file($this->path, str_repeat('a', 400) . '.pdf');

        $this->assertStringEndsWith('.pdf"', $r->getHeaderLine('Content-Disposition'));
    }

    public function testOverlongInvalidUtf8NameNeverBecomesEmpty(): void
    {
        // A CP1252 name (invalid UTF-8 from byte 0) used to be trimmed away completely,
        // leaving filename="" behind.
        $r = Response::file($this->path, "\xC4rztebrief_" . str_repeat('x', 249) . '.pdf');
        $disposition = $r->getHeaderLine('Content-Disposition');

        $this->assertStringNotContainsString('filename=""', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
    }

    public function testOverlongDotsOnlyNameStillFallsBackToDownload(): void
    {
        $r = Response::file($this->path, str_repeat('.', 400));

        $this->assertSame('attachment; filename="download"', $r->getHeaderLine('Content-Disposition'));
    }

    public function testOverlongFilenameIsTruncatedWithoutBreakingUtf8(): void
    {
        $r = Response::file($this->path, str_repeat('ä', 400) . '.pdf');
        $disposition = $r->getHeaderLine('Content-Disposition');

        $this->assertLessThan(1024, strlen($disposition));
        $encoded = explode("UTF-8''", $disposition)[1] ?? '';
        $this->assertNotSame('', $encoded);
        $this->assertSame(1, preg_match('//u', rawurldecode($encoded)), 'truncation must not cut a UTF-8 sequence');
    }
}
