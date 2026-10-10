<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Contract\ResponderInterface;
use Sodaho\Router\Service\RfcResponder;

class RfcResponderTest extends TestCase
{
    public function testImplementsResponderInterface(): void
    {
        $responder = new RfcResponder();
        $this->assertInstanceOf(ResponderInterface::class, $responder);
    }

    public function testGetContentType(): void
    {
        $responder = new RfcResponder();
        $this->assertSame('application/problem+json', $responder->getContentType());
    }

    // ==================== Success Responses ====================

    public function testFormatSuccessWithData(): void
    {
        $responder = new RfcResponder();
        $result = $responder->formatSuccess(['id' => 1, 'name' => 'Test']);

        $this->assertSame(['data' => ['id' => 1, 'name' => 'Test']], $result);
    }

    public function testFormatSuccessWithMessage(): void
    {
        $responder = new RfcResponder();
        $result = $responder->formatSuccess(['id' => 1], 'Created successfully');

        $this->assertSame([
            'data' => ['id' => 1],
            'message' => 'Created successfully',
        ], $result);
    }

    public function testFormatSuccessWithMeta(): void
    {
        $responder = new RfcResponder();
        $result = $responder->formatSuccess(
            ['items' => []],
            null,
            ['pagination' => ['page' => 1, 'total' => 100]]
        );

        $this->assertSame([
            'data' => ['items' => []],
            'meta' => ['pagination' => ['page' => 1, 'total' => 100]],
        ], $result);
    }

    // ==================== Error Responses ====================

    public function testFormatErrorBasic(): void
    {
        $responder = new RfcResponder();
        $result = $responder->formatError('Something went wrong');

        $this->assertSame([
            'type' => 'about:blank',
            'title' => 'Something went wrong',
        ], $result);
    }

    public function testFormatErrorWithCodeDefaultTypeBase(): void
    {
        $responder = new RfcResponder();
        $result = $responder->formatError('Not found', 'NOT_FOUND');

        // With default about:blank typeBaseUri, type stays about:blank
        $this->assertSame([
            'type' => 'about:blank',
            'title' => 'Not found',
        ], $result);
    }

    public function testFormatErrorWithCustomTypeBaseUri(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors');
        $result = $responder->formatError('Not found', 'NOT_FOUND');

        $this->assertSame([
            'type' => 'https://api.example.com/errors/not-found',
            'title' => 'Not found',
        ], $result);
    }

    public function testFormatErrorWithValidationError(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors');
        $result = $responder->formatError('Validation failed', 'VALIDATION_ERROR');

        $this->assertSame([
            'type' => 'https://api.example.com/errors/validation-error',
            'title' => 'Validation failed',
        ], $result);
    }

    public function testFormatErrorWithDetail(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors');
        $result = $responder->formatError(
            'Not found',
            'NOT_FOUND',
            ['detail' => 'User with ID 123 not found']
        );

        $this->assertSame([
            'type' => 'https://api.example.com/errors/not-found',
            'title' => 'Not found',
            'detail' => 'User with ID 123 not found',
        ], $result);
    }

    public function testFormatErrorWithInstance(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors');
        $result = $responder->formatError(
            'Not found',
            'NOT_FOUND',
            ['instance' => '/users/123']
        );

        $this->assertSame([
            'type' => 'https://api.example.com/errors/not-found',
            'title' => 'Not found',
            'instance' => '/users/123',
        ], $result);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function detailsThatNameAStemMember(): array
    {
        return [
            // Up to 2.1.1 each of these replaced the member: a 400 that said "status": 200
            'status' => [['status' => 200]],
            'status as a string' => [['status' => '422']],
            'status that is no status code' => [['status' => 'abc']],
            'type' => [['type' => 'https://elsewhere.example/ok']],
            'title' => [['title' => 'Everything is fine']],
            'all three' => [['type' => 'about:blank', 'title' => 'Fine', 'status' => 200]],
        ];
    }

    /**
     * "type", "title" and "status" are the responder's and the response's: what the details
     * say under those names does not replace them (A10). "status" is added by Response.
     *
     * @param array<string, mixed> $details
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('detailsThatNameAStemMember')]
    public function testDetailsDoNotReplaceTypeTitleOrStatus(array $details): void
    {
        $result = new RfcResponder('https://api.example.com/errors')->formatError('Not found', 'NOT_FOUND', $details + ['other' => 1]);

        $this->assertSame(['type' => 'https://api.example.com/errors/not-found', 'title' => 'Not found', 'other' => 1], $result);
    }

    public function testFormatErrorWithExtensionMembers(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors');
        $result = $responder->formatError(
            'Validation failed',
            'VALIDATION_ERROR',
            [
                'fields' => ['email' => 'Invalid email format'],
            ]
        );

        $this->assertSame([
            'type' => 'https://api.example.com/errors/validation-error',
            'title' => 'Validation failed',
            'fields' => ['email' => 'Invalid email format'],
        ], $result);
    }

    public function testFormatErrorFullRfc7807Response(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors');
        $result = $responder->formatError(
            'Insufficient funds',
            'INSUFFICIENT_FUNDS',
            [
                'status' => 403,
                'detail' => 'Your current balance is 30, but the item costs 50.',
                'instance' => '/accounts/12345/transactions/67890',
                'balance' => 30,
                'cost' => 50,
            ]
        );

        $this->assertSame([
            'type' => 'https://api.example.com/errors/insufficient-funds',
            'title' => 'Insufficient funds',
            'detail' => 'Your current balance is 30, but the item costs 50.',
            'instance' => '/accounts/12345/transactions/67890',
            'balance' => 30,
            'cost' => 50,
        ], $result);
    }

    public function testTypeBaseUriTrailingSlashTrimmed(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors/');
        $result = $responder->formatError('Test', 'TEST_ERROR');

        $this->assertSame([
            'type' => 'https://api.example.com/errors/test-error',
            'title' => 'Test',
        ], $result);
    }

    public function testCodeConvertedToLowercaseSlug(): void
    {
        $responder = new RfcResponder('https://api.example.com/errors');

        // SCREAMING_SNAKE_CASE -> lowercase-kebab-case
        $result = $responder->formatError('Test', 'SOME_LONG_ERROR_CODE');
        $this->assertSame('https://api.example.com/errors/some-long-error-code', $result['type']);
    }
}
