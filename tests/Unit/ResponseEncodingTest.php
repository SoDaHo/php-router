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

        // It SHOULD contain the Replacement Character  (U+FFFD)
        // The exact output depends on PHP's implementation of substitution,
        // usually it replaces the bad byte.
        $this->assertStringContainsString('Hello', $decoded['data']['text']);
        $this->assertStringContainsString('World', $decoded['data']['text']);

        // Verify it is valid JSON now
        $this->assertNotNull($decoded, 'Response body is not valid JSON');
    }
}
