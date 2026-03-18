# php-router

Lightweight PHP Router for REST APIs and SPAs. Standardized JSON responses, middleware, caching.

## Why This Library?

**What it does:**
- PSR-7/PSR-15 compliant routing with typed route parameters and auto-casting
- Standardized JSON response format (pluggable via `ResponderInterface`)
- Route caching with HMAC integrity verification
- Middleware, route groups, named routes, URL generation

**What it deliberately does not:**
- No optional route segments, no inline regex, no route priority system
- No CORS, CSRF, authentication, or rate limiting (use middleware)
- No async/Swoole runtime (use `handle()` + your own emitter)
- Not optimized for >500 dynamic routes (O(n) matching)

## Installation

```bash
composer require sodaho/php-router
```

## Quick Start

```php
use Sodaho\Router\Router;
use Sodaho\Router\Response;

$router = Router::create();
$router->loadRoutes(__DIR__ . '/routes.php');
$router->run();
```

**routes.php:**
```php
use Sodaho\Router\RouteCollector;

return function (RouteCollector $r) {
    $r->get('/users', [UserController::class, 'index']);
    $r->get('/users/{id:int}', [UserController::class, 'show']);
    $r->post('/users', [UserController::class, 'store']);
};
```

**Controller:**
```php
use Sodaho\Router\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class UserController
{
    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $user = ['id' => $id, 'name' => 'John'];
        return Response::success($user);
    }
}
```

## HTTP Methods

```php
$r->get('/users', $handler);
$r->post('/users', $handler);
$r->put('/users/{id}', $handler);
$r->patch('/users/{id}', $handler);
$r->delete('/users/{id}', $handler);
$r->options('/users', $handler);
$r->head('/users', $handler);

// Multiple methods
$r->match(['GET', 'POST'], '/search', $handler);

// All methods
$r->any('/webhook', $handler);
```

## Route Parameters

```php
// Basic parameter
$r->get('/users/{id}', $handler);

// With type constraint (validates + casts automatically)
$r->get('/users/{id:int}', $handler);        // Integer
$r->get('/price/{value:float}', $handler);   // Decimal
$r->get('/active/{flag:bool}', $handler);    // Boolean (true/false/1/0)

// Pattern constraints
$r->get('/posts/{slug:slug}', $handler);     // a-z, 0-9, hyphens
$r->get('/users/{uuid:uuid}', $handler);     // UUID format
$r->get('/files/{path:any}', $handler);      // Anything (including slashes)
$r->get('/codes/{code:alphanum}', $handler); // Alphanumeric
```

### Available Patterns

| Shorthand | Regex | Example |
|-----------|-------|---------|
| `int` | `-?\d+` | `{id:int}` → 123, -5 |
| `float` | `-?\d+(?:\.\d+)?` | `{price:float}` → 19.99 |
| `bool` | `true\|false\|0\|1` | `{active:bool}` → true |
| `alpha` | `[a-zA-Z]+` | `{name:alpha}` → abc |
| `alphanum` | `[a-zA-Z0-9]+` | `{code:alphanum}` → abc123 |
| `slug` | `[a-z0-9-]+` | `{slug:slug}` → my-post |
| `uuid` | `[0-9a-f]{8}-...` | `{id:uuid}` → 550e8400-... |
| `any` | `.*` | `{path:any}` → anything/here |

### Custom Patterns

```php
$r->addPattern('date', '\d{4}-\d{2}-\d{2}');
$r->get('/events/{date:date}', $handler);  // 2024-12-06
```

### Accessing Parameters

```php
// Option A: Named arguments (recommended)
public function show(ServerRequestInterface $request, int $id): ResponseInterface
{
    // $id is already typed and validated
}

// Option B: From request attributes
public function show(ServerRequestInterface $request): ResponseInterface
{
    $id = $request->getAttribute('id');
}
```

## Route Groups

```php
$r->group('/api', function (RouteCollector $r) {
    $r->group('/v1', function (RouteCollector $r) {
        $r->get('/users', [UserController::class, 'index']);
    });
});
// → /api/v1/users
```

## Middleware

```php
use Psr\Http\Server\MiddlewareInterface;

// Per route
$r->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(AuthMiddleware::class);

// Multiple middleware
$r->get('/admin', [AdminController::class, 'index'])
    ->middleware([AuthMiddleware::class, AdminMiddleware::class]);

// Middleware group
$r->middlewareGroup([AuthMiddleware::class, LogMiddleware::class], function ($r) {
    $r->get('/profile', [ProfileController::class, 'show']);
    $r->put('/profile', [ProfileController::class, 'update']);
});
```

**Route parameters are available in middleware:**
```php
class OwnershipMiddleware implements MiddlewareInterface
{
    public function process($request, $handler): ResponseInterface
    {
        $orderId = $request->getAttribute('id');  // Available!
        // ... ownership check
        return $handler->handle($request);
    }
}
```

## Named Routes & URL Generation

```php
$r->get('/users/{id}', [UserController::class, 'show'])
    ->name('user.show');

// Generate URL
$url = $router->url('user.show', ['id' => 5]);
// → /users/5

// Absolute URL (requires APP_URL env variable)
$url = $router->absoluteUrl('user.show', ['id' => 5]);
// → https://example.com/users/5
```

## Redirect Routes

```php
$r->redirect('/old-url', '/new-url');           // 302 Temporary
$r->redirect('/old-url', '/new-url', 301);      // 301 Permanent
$r->redirect('/users/{id}/profile', '/profile/{id}');  // With parameters
```

## Response Helpers

### Success Responses

```php
Response::success($data);                              // 200
Response::success($data, 'Created successfully');      // 200 with message
Response::created($data);                              // 201
Response::created($data, 'User created', '/users/5');  // 201 with Location header
Response::accepted($data);                             // 202
Response::noContent();                                 // 204
Response::paginated($items, $total, $page, $perPage);  // 200 with pagination meta
```

### Error Responses

```php
Response::error('Something went wrong', 400);              // Generic error
Response::error('Invalid input', 400, 'INVALID_INPUT');    // With error code
Response::notFound('User', 123);                           // 404 "User with identifier 123 not found"
Response::notFound();                                      // 404 "Resource not found"
Response::unauthorized();                                  // 401
Response::unauthorized('Token expired');                   // 401 with message
Response::forbidden();                                     // 403
Response::validationError(['email' => 'Invalid format']);  // 422
Response::methodNotAllowed(['GET', 'POST']);               // 405
Response::tooManyRequests(60);                             // 429 with Retry-After
Response::serverError();                                   // 500
```

### Other Responses

```php
Response::html($content);                                // text/html
Response::html($content, 404);                           // text/html with status
Response::text($content);                                // text/plain
Response::redirect('/new-url');                          // 302
Response::redirect('/new-url', 301);                     // 301
Response::download($content, 'file.pdf');                // Attachment
Response::download($content, 'file.pdf', 'application/pdf');
```

### JSON Structure

**Success:**
```json
{
    "success": true,
    "data": { ... },
    "message": "Optional message",
    "meta": { "pagination": { ... } }
}
```

**Error:**
```json
{
    "success": false,
    "message": "User-friendly message",
    "error": {
        "message": "Technical message",
        "code": "ERROR_CODE",
        "details": { ... }
    }
}
```

## Configuration

### Via Config Array

```php
$router = Router::create([
    'debug' => true,
    'basePath' => '/api',
    'baseUrl' => 'https://api.example.com',
    'trailingSlash' => 'ignore',
]);
```

### Via Environment Variables

```php
// .env
APP_DEBUG=true
APP_ENV=development
APP_URL=https://api.example.com
ROUTER_BASE_PATH=/api
ROUTER_TRAILING_SLASH=ignore
ROUTER_CACHE_FILE=/var/cache/routes.php
ROUTER_CACHE_KEY=your-secret-key
```

### Via Fluent API

```php
$router = Router::create()
    ->setDebug(true)
    ->setBasePath('/api')
    ->enableCache(__DIR__ . '/cache/routes.php');
```

### Options

| Config Key | ENV Variable | Default | Description |
|------------|--------------|---------|-------------|
| `debug` | `APP_DEBUG` | `false` | Enable debug mode (detailed errors) |
| - | `APP_ENV` | `production` | If `dev`/`local`/`development` → debug=true |
| `basePath` | `ROUTER_BASE_PATH` | `''` | URL prefix for all routes |
| `baseUrl` | `APP_URL` | `null` | Base URL for `absoluteUrl()` |
| `trailingSlash` | `ROUTER_TRAILING_SLASH` | `'strict'` | `'strict'` or `'ignore'` |
| `cacheFile` | `ROUTER_CACHE_FILE` | `null` | Path to cache file |
| `cacheSignature` | `ROUTER_CACHE_KEY` | `null` | HMAC key for cache integrity |

## Caching

```php
// Enable cache with optional HMAC signature
$router = Router::create()
    ->enableCache(__DIR__ . '/cache/routes.php', 'your-secret-key')
    ->loadRoutes(__DIR__ . '/routes.php');

$router->run();
```

**Note:** Closures cannot be cached. Use `[Controller::class, 'method']` syntax.

## Hooks (Logging)

```php
// Log successful dispatches
$router->on('dispatch', function (array $data) {
    // $data: method, path, route, handler, params, duration
    $logger->info("Route matched", $data);
});

// Log 404 errors
$router->on('notFound', function (array $data) {
    // $data: method, path
    $logger->warning("404", $data);
});

// Log 405 errors
$router->on('methodNotAllowed', function (array $data) {
    // $data: method, path, allowed_methods
    $logger->warning("405", $data);
});

// Log exceptions
$router->on('error', function (array $data) {
    // $data: method, path, exception
    $logger->error("Error", $data);
});
```

**Note:** Hook exceptions are caught and logged to stderr. They never affect the response.

## PSR-15 Compatibility

```php
// run() for simple apps
$router->run();

// handle() for PSR-15 integration
$request = $serverRequestFactory->fromGlobals();
$response = $router->handle($request);  // Returns ResponseInterface

// Emit response yourself
(new SapiEmitter())->emit($response);
```

## Dependency Injection

```php
use Psr\Container\ContainerInterface;

$router = Router::create()
    ->setContainer($container)  // Any PSR-11 container
    ->loadRoutes(__DIR__ . '/routes.php');

// Controllers are resolved via container if available
// Otherwise instantiated directly
```

## Exceptions

All exceptions extend `RouterException`:

```php
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Exception\NotFoundException;
use Sodaho\Router\Exception\MethodNotAllowedException;
use Sodaho\Router\Exception\RouteNotFoundException;
use Sodaho\Router\Exception\DuplicateRouteException;
use Sodaho\Router\Exception\CacheException;

try {
    $router->run();
} catch (RouterException $e) {
    // Catches all router exceptions
    echo $e->getMessage();
    echo $e->getDebugMessage();  // Additional debug info
}
```

| Exception | When |
|-----------|------|
| `NotFoundException` | Route not found (404) |
| `MethodNotAllowedException` | Wrong HTTP method (405) |
| `RouteNotFoundException` | Named route doesn't exist (URL generation) |
| `DuplicateRouteException` | Same method+pattern registered twice |
| `CacheException` | Cache read/write/signature failure |

## Trailing Slash Handling

```php
// Default: strict (exact match)
$r->get('/users', $handler);   // Only matches /users
$r->get('/users/', $handler);  // Only matches /users/

// Ignore mode: /users matches both /users and /users/
$router = Router::create(['trailingSlash' => 'ignore']);
```

## SPA Catch-All (Vue/React)

```php
// API routes first
$r->group('/api', function ($r) {
    $r->get('/users', [UserController::class, 'index']);
});

// Catch-all for Vue Router (history mode)
$r->get('/{any:any}', [PageController::class, 'index']);
```

```php
class PageController
{
    public function index($request): ResponseInterface
    {
        return Response::html(file_get_contents('public/index.html'));
    }
}
```

## Quick Boot

```php
// One-liner for simple apps
Router::boot(['debug' => true], __DIR__ . '/routes.php');
```

## Webserver Configuration

### Apache (.htaccess)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

### nginx

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Custom Response Formats

The router uses `JsonResponder` by default. You can swap it for RFC 7807 or custom formats:

```php
use Sodaho\Router\Response;
use Sodaho\Router\Service\RfcResponder;

// RFC 7807 Problem Details format
Response::setResponder(new RfcResponder('https://api.example.com/errors'));

// Error responses now use RFC 7807:
// {
//   "type": "https://api.example.com/errors/not-found",
//   "title": "User not found",
//   "status": 404,
//   "detail": "User with ID 123 not found"
// }
```

**Create your own responder:**
```php
use Sodaho\Router\Contract\ResponderInterface;

class XmlResponder implements ResponderInterface
{
    public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
    {
        // Return array that will be converted to XML
    }

    public function formatError(string $message, ?string $code = null, ?array $details = null): array
    {
        // Return array for error responses
    }

    public function getContentType(): string
    {
        return 'application/xml';  // Used for 4xx/5xx responses
    }

    public function getSuccessContentType(): string
    {
        return 'application/xml';  // Used for 2xx responses
    }
}
```

**Reset in tests:**
```php
protected function tearDown(): void
{
    Response::reset(); // Restores default JsonResponder
}
```

## Limitations

**What this router does NOT support:**

| Feature | Reason |
|---------|--------|
| Optional segments `[/suffix]` | Complexity vs. benefit. Define two routes instead. |
| Regex in route patterns | Use predefined patterns or `addPattern()`. |
| Route priority/ordering | Routes match in definition order. Define specific routes first. |
| Async/Swoole out-of-box | Use `handle()` method, not `run()`. Emit response yourself. |
| >500 dynamic routes efficiently | O(n) matching. Consider splitting into microservices. |

**Workarounds:**

```php
// Instead of optional segments:
$r->get('/users', $handler);
$r->get('/users/{id}', $handler);

// Instead of inline regex:
$r->addPattern('date', '\d{4}-\d{2}-\d{2}');
$r->get('/events/{date:date}', $handler);
```

## Performance

### Route Caching

**Always enable caching in production:**

```php
$router = Router::create()
    ->enableCache(__DIR__ . '/../var/cache/routes.php', $_ENV['APP_KEY'])
    ->loadRoutes(__DIR__ . '/routes.php');
```

| Mode | 50 Routes | 200 Routes |
|------|-----------|------------|
| No cache | ~2-5ms | ~5-15ms |
| With cache | ~0.1ms | ~0.2ms |

### Route Matching Complexity

| Route Type | Complexity | Example |
|------------|------------|---------|
| Static | O(1) | `/users`, `/api/health` |
| Dynamic | O(n) | `/users/{id}`, `/posts/{slug}` |

**Tips:**
- Static routes are instant (hash lookup)
- Dynamic routes loop through candidates
- Define most-used routes first
- Keep dynamic routes under 500 for best performance

### Memory

- Route cache uses OPcache (no memory parsing)
- ~1KB per route in memory
- 100 routes ≈ 100KB memory footprint

## Security Best Practices

### Open Redirect Prevention

**Never redirect to user input without validation:**

```php
// DANGEROUS - Open Redirect vulnerability!
$r->get('/goto', function ($request) {
    $url = $request->getQueryParams()['url'];
    return Response::redirect($url);  // Attacker: ?url=https://evil.com
});

// SAFE - Whitelist or validate
$r->get('/goto', function ($request) {
    $url = $request->getQueryParams()['url'] ?? '/';
    $allowed = ['/', '/dashboard', '/profile'];

    if (!in_array($url, $allowed, true)) {
        return Response::error('Invalid redirect', 400);
    }

    return Response::redirect($url);
});
```

### CSRF Protection

This router does **not** include CSRF protection. For state-changing operations:

```php
// Option 1: Use a CSRF middleware
$r->middlewareGroup([CsrfMiddleware::class], function ($r) {
    $r->post('/users', [UserController::class, 'store']);
    $r->delete('/users/{id}', [UserController::class, 'destroy']);
});

// Option 2: For SPAs - use SameSite cookies + custom header
// Frontend sends: X-Requested-With: XMLHttpRequest
// Backend validates header presence
```

### Input Validation

Route parameter types (`{id:int}`) validate format, **not business logic:**

```php
// {id:int} ensures $id is an integer, but NOT that:
// - The user exists
// - The current user can access it
// - The ID is within valid range

public function show(ServerRequestInterface $request, int $id): ResponseInterface
{
    // Always validate business logic!
    $user = $this->userRepository->find($id);

    if ($user === null) {
        return Response::notFound('User', $id);
    }

    if (!$this->canAccess($request, $user)) {
        return Response::forbidden();
    }

    return Response::success($user);
}
```

### Debug Mode

**Never enable debug mode in production:**

```php
// Debug mode exposes:
// - Full exception messages
// - Stack traces
// - File paths
// - Internal error details

// .env.production
APP_DEBUG=false
APP_ENV=production
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Requirements

- PHP ^8.2
- PSR-7 HTTP Message (nyholm/psr7)
- PSR-15 HTTP Handler/Middleware

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
