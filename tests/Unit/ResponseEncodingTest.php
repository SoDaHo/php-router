<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Response;

class ResponseEncodingTest extends TestCase
{
    public function testValidUtf8IsPreserved(): void
    {
        // Test cases with valid UTF-8
        $data = [
            'german' => 'Müller & Söhne',
            'city' => 'Zürich',
            'emoji' => 'Rocket 🚀 Launch',
            'symbols' => '€ Euro Sign',
            'mixed' => 'Hällo Wörld 🎉',
        ];

        $response = Response::success($data);
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        // Verify strict equality
        $this->assertSame($data['german'], $decoded['data']['german'], 'Umlauts damaged!');
        $this->assertSame($data['city'], $decoded['data']['city'], 'Umlauts damaged!');
        $this->assertSame($data['emoji'], $decoded['data']['emoji'], 'Emojis damaged!');
        $this->assertSame($data['symbols'], $decoded['data']['symbols'], 'Symbols damaged!');

        // Verify JSON structure (no escaped unicode like \u00fc) because we use JSON_UNESCAPED_UNICODE
        $this->assertStringContainsString('Müller', $body, 'JSON should contain literal Müller');
        $this->assertStringContainsString('🚀', $body, 'JSON should contain literal Emoji');
    }

    public function testInvalidUtf8IsSubstituted(): void
    {
        // "Invalid" is a string with a bad byte sequence
        // \xC3 is start of 2-byte seq, but we end string there -> Invalid
        $badString = "Hello \xC3 World";

        $response = Response::success(['text' => $badString]);
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        // It should NOT crash (which it did before)
        $this->assertSame(200, $response->getStatusCode());

        // The bad byte is replaced by the replacement character U+FFFD, not dropped: a
        // text that only contained 'Hello' and 'World' would pass as well if it were
        // dropped (JSON_INVALID_UTF8_IGNORE) — and two words would be glued together
        $this->assertSame("Hello \u{FFFD} World", $decoded['data']['text']);
        // In the body as it is, unescaped (JSON_UNESCAPED_UNICODE)
        $this->assertSame('{"success":true,"data":{"text":"Hello ' . "\u{FFFD}" . ' World"}}', $body);
    }
}
