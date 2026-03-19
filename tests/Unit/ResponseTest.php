<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Router\Contract\ResponderInterface;
use Sodaho\Router\Response;
use Sodaho\Router\Service\JsonResponder;
use Sodaho\Router\Service\RfcResponder;

class ResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        Response::reset();
    }

    public function testSuccessResponse(): void
    {
        $response = Response::success(['id' => 1, 'name' => 'Test']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame(['id' => 1, 'name' => 'Test'], $body['data']);
    }

    public function testSuccessWithMessage(): void
    {
        $response = Response::success(['id' => 1], 'Created successfully');

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Created successfully', $body['message']);
    }

    public function testCreatedResponse(): void
    {
        $response = Response::created(['id' => 5], 'User created', '/users/5');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('/users/5', $response->getHeaderLine('Location'));
    }

    public function testNoContentResponse(): void
    {
        $response = Response::noContent();

        $this->assertSame(204, $response->getStatusCode());
    }

    public function testNotFoundResponse(): void
    {
        $response = Response::notFound('User', 123);

        $this->assertSame(404, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('User', $body['message']);
        $this->assertStringContainsString('123', $body['message']);
    }

    public function testMethodNotAllowedResponse(): void
    {
        $response = Response::methodNotAllowed(['GET', 'POST']);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testValidationErrorResponse(): void
    {
        $response = Response::validationError([
            'email' => 'Invalid email format',
            'password' => 'Too short',
        ]);

        $this->assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('VALIDATION_ERROR', $body['error']['code']);
        $this->assertArrayHasKey('fields', $body['error']['details']);
    }

    public function testServerErrorResponse(): void
    {
        $response = Response::serverError('Something went wrong', ['trace' => '...']);

        $this->assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('SERVER_ERROR', $body['error']['code']);
    }

    public function testRedirectResponse(): void
    {
        $response = Response::redirect('/new-location', 301);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new-location', $response->getHeaderLine('Location'));
    }

    public function testHtmlResponse(): void
    {
        $response = Response::html('<h1>Hello</h1>');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<h1>Hello</h1>', (string) $response->getBody());
    }

    public function testSuccessWithMeta(): void
    {
        $response = Response::success(['items' => [1, 2, 3]], null, ['count' => 3]);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(3, $body['meta']['count']);
    }

    public function testAcceptedResponse(): void
    {
        $response = Response::accepted(['job_id' => 'abc123'], 'Processing started');

        $this->assertSame(202, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertSame('abc123', $body['data']['job_id']);
        $this->assertSame('Processing started', $body['message']);
    }

    public function testPaginatedResponse(): void
    {
        $response = Response::paginated(
            items: [['id' => 1], ['id' => 2]],
            total: 50,
            page: 2,
            perPage: 10
        );

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertCount(2, $body['data']);
        $this->assertSame(50, $body['meta']['pagination']['total']);
        $this->assertSame(2, $body['meta']['pagination']['current_page']);
        $this->assertSame(5, $body['meta']['pagination']['last_page']);
        $this->assertSame(11, $body['meta']['pagination']['from']);
        $this->assertSame(20, $body['meta']['pagination']['to']);
    }

    public function testPaginatedEmptyResult(): void
    {
        $response = Response::paginated([], 0, 1, 10);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(0, $body['meta']['pagination']['total']);
        $this->assertSame(0, $body['meta']['pagination']['from']);
        $this->assertSame(0, $body['meta']['pagination']['to']);
        $this->assertSame(0, $body['meta']['pagination']['last_page']);
    }

    public function testPaginatedRejectsZeroPerPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$perPage must be at least 1');

        Response::paginated([], 10, 1, 0);
    }

    public function testPaginatedRejectsNegativePerPage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Response::paginated([], 10, 1, -5);
    }

    public function testErrorResponse(): void
    {
        $response = Response::error('Something failed', 400, 'CUSTOM_ERROR', ['field' => 'value']);

        $this->assertSame(400, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('CUSTOM_ERROR', $body['error']['code']);
        $this->assertSame('value', $body['error']['details']['field']);
    }

    public function testUnauthorizedResponse(): void
    {
        $response = Response::unauthorized();
        $this->assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('UNAUTHORIZED', $body['error']['code']);

        // With custom message
        $response2 = Response::unauthorized('Token expired');
        $body2 = json_decode((string) $response2->getBody(), true);
        $this->assertSame('Token expired', $body2['message']);
    }

    public function testForbiddenResponse(): void
    {
        $response = Response::forbidden();
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('FORBIDDEN', $body['error']['code']);

        // With custom message
        $response2 = Response::forbidden('Access denied to resource');
        $body2 = json_decode((string) $response2->getBody(), true);
        $this->assertSame('Access denied to resource', $body2['message']);
    }

    public function testTooManyRequestsResponse(): void
    {
        $response = Response::tooManyRequests(60);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->getHeaderLine('Retry-After'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('TOO_MANY_REQUESTS', $body['error']['code']);
    }

    public function testTextResponse(): void
    {
        $response = Response::text('Plain text content', 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('Plain text content', (string) $response->getBody());
    }

    public function testDownloadResponse(): void
    {
        $response = Response::download('file content', 'report.csv', 'text/csv');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="report.csv"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('12', $response->getHeaderLine('Content-Length'));
        $this->assertSame('file content', (string) $response->getBody());
    }

    public function testNotFoundVariants(): void
    {
        // Only resource name
        $response1 = Response::notFound('User');
        $body1 = json_decode((string) $response1->getBody(), true);
        $this->assertSame('User not found', $body1['message']);

        // No parameters
        $response2 = Response::notFound();
        $body2 = json_decode((string) $response2->getBody(), true);
        $this->assertSame('Resource not found', $body2['message']);
    }

    public function testCreatedWithoutLocation(): void
    {
        $response = Response::created(['id' => 1]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    public function testServerErrorDefaults(): void
    {
        $response = Response::serverError();

        $this->assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Internal server error', $body['message']);
        $this->assertArrayNotHasKey('details', $body['error']);
    }

    public function testHtmlWithStatus(): void
    {
        $response = Response::html('<h1>Error</h1>', 500);

        $this->assertSame(500, $response->getStatusCode());
    }

    // ==================== Responder Tests ====================

    public function testGetResponderReturnsJsonResponderByDefault(): void
    {
        $responder = Response::getResponder();

        $this->assertInstanceOf(JsonResponder::class, $responder);
    }

    public function testSetResponderChangesResponder(): void
    {
        $rfcResponder = new RfcResponder('https://example.com/errors');

        Response::setResponder($rfcResponder);

        $this->assertSame($rfcResponder, Response::getResponder());
    }

    public function testSetResponderAffectsContentType(): void
    {
        Response::setResponder(new RfcResponder());

        $response = Response::error('Not found', 404);

        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testSetResponderAffectsErrorFormat(): void
    {
        Response::setResponder(new RfcResponder('https://api.example.com/errors'));

        $response = Response::error('Not found', 404, 'NOT_FOUND');
        $body = json_decode((string) $response->getBody(), true);

        // RFC 7807 format
        $this->assertArrayHasKey('type', $body);
        $this->assertArrayHasKey('title', $body);
        $this->assertSame('https://api.example.com/errors/not-found', $body['type']);
        $this->assertSame('Not found', $body['title']);

        // NOT JsonResponder format
        $this->assertArrayNotHasKey('success', $body);
    }

    public function testResetRestoresDefaultResponder(): void
    {
        Response::setResponder(new RfcResponder());

        // Verify it's changed
        $this->assertInstanceOf(RfcResponder::class, Response::getResponder());

        // Reset
        Response::reset();

        // Verify it's back to default
        $this->assertInstanceOf(JsonResponder::class, Response::getResponder());
    }

    public function testResetAffectsSubsequentResponses(): void
    {
        Response::setResponder(new RfcResponder());

        // Response with RFC format
        $response1 = Response::error('Test', 400);
        $this->assertSame('application/problem+json', $response1->getHeaderLine('Content-Type'));

        // Reset
        Response::reset();

        // Response with JSON format
        $response2 = Response::error('Test', 400);
        $this->assertSame('application/json', $response2->getHeaderLine('Content-Type'));
    }

    public function testRfcResponderUsesCorrectContentTypes(): void
    {
        Response::setResponder(new RfcResponder());

        // RFC 7807: Success responses use application/json (not problem+json)
        $success = Response::success(['data' => 'test']);
        $this->assertSame('application/json', $success->getHeaderLine('Content-Type'));

        // RFC 7807: Error responses use application/problem+json
        $error = Response::notFound();
        $this->assertSame('application/problem+json', $error->getHeaderLine('Content-Type'));

        $serverError = Response::serverError();
        $this->assertSame('application/problem+json', $serverError->getHeaderLine('Content-Type'));
    }

    public function testCustomResponder(): void
    {
        $customResponder = new class () implements ResponderInterface {
            public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
            {
                return ['custom' => true, 'payload' => $data];
            }

            public function formatError(string $message, ?string $code = null, ?array $details = null): array
            {
                return ['custom_error' => true, 'msg' => $message];
            }

            public function getContentType(): string
            {
                return 'application/vnd.custom+json';
            }

            public function getSuccessContentType(): string
            {
                return 'application/vnd.custom+json';
            }
        };

        Response::setResponder($customResponder);

        $response = Response::success(['test' => 1]);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame('application/vnd.custom+json', $response->getHeaderLine('Content-Type'));
        $this->assertTrue($body['custom']);
        $this->assertSame(['test' => 1], $body['payload']);
    }
}
