# php-router

Lightweight PHP Router for REST APIs and SPAs. Standardized JSON responses, middleware.

## Why This Library?

**What it does:**
- PSR-7/PSR-15 compliant routing with typed route parameters and auto-casting
- Standardized JSON response format (pluggable via `ResponderInterface`)
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

A `HEAD` request to a route registered with `get()` is answered by that route: its
middleware and handler run, and the response goes out with its status and headers but
without its body. `Allow` names `HEAD` right behind `GET`. The router does not change the
method — middleware and handler see `HEAD` (unless a middleware of yours rewrites it) and
are free to answer differently than for GET. Keep GET handlers free of side effects, or
give them a `head()` route of their own: link scanners and mail clients ask with HEAD. No
response to a HEAD request leaves `handle()` with a body, whoever wrote it; that includes
a request a middleware turned into HEAD, or out of it, on its way in.

With `'implicitHead' => false` HEAD needs a route of its own (`$r->head()`), a GET route
answers 405 as it did in 1.x, and `handle()` leaves bodies alone (`run()` never sends one
for a HEAD request, either way).

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
| `bool` | `true\|false\|0\|1` (case-insensitive) | `{active:bool}` → true, TRUE |
| `alpha` | `[a-zA-Z]+` | `{name:alpha}` → abc |
| `alphanum` | `[a-zA-Z0-9]+` | `{code:alphanum}` → abc123 |
| `slug` | `[a-z0-9-]+` | `{slug:slug}` → my-post |
| `uuid` | `[0-9a-fA-F]{8}-...` | `{id:uuid}` → 550e8400-... |
| `ulid` | `[0-9A-Za-z]{26}` | `{id:ulid}` → 01ARZ3NDEKTSV4RRFFQ69G5FAV |
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

// Option B: From request attributes — additionally available, e.g. inside middleware.
// The handler must still declare every placeholder of its route; a handler that omits
// one fails with "Unknown named parameter".
public function show(ServerRequestInterface $request, int $id): ResponseInterface
{
    $id === $request->getAttribute('id');   // same value
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

## Route Attributes

What your application wants to know about a route before its handler runs — the response
format, whether browsers may call it — goes on the route. The router does not interpret
attributes.

```php
$r->post('/token', [TokenController::class, 'token'])
    ->attribute('format', 'oauth');

$r->attributeGroup(['format' => 'envelope', 'cors' => true], function (RouteCollector $r) {
    $r->get('/me', [AccountController::class, 'show']);
    $r->get('/me/avatar', [AvatarController::class, 'show'])->attribute('format', 'binary');
});

// Reading them, e.g. in a middleware (Sodaho\Router\Route)
$route = $request->getAttribute(Route::class);
$route->getAttribute('format', 'envelope');  // second argument: default when not set
$route->attributes;                          // all of them
```

Groups nest: the inner group wins per key, and `attribute()` on the route wins over every
group. See [Looking a Route Up](#looking-a-route-up) for where the route comes from.

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

### Middleware for Every Request

Route middleware only runs when a route matched. Middleware added to the router runs for
every request — also those that end in 404, 405 or 400 — and sees every response, including
the one made from an exception. That is the place for an access log, security headers or
CORS.

```php
$router->middleware(AccessLog::class)       // first added = outermost
       ->middleware([$securityHeaders, $cors]);
```

The request it gets already carries the result of the route lookup:

```php
use Sodaho\Router\RouteMatch;

public function process($request, $handler): ResponseInterface
{
    $match = $request->getAttribute(RouteMatch::class);

    if ($match->status === RouteMatch::NOT_FOUND) {
        return Response::html($this->notFoundPage, 404);   // answer instead of the router
    }

    if ($request->getMethod() === 'OPTIONS' && $match->route?->getAttribute('cors')) {
        return $this->preflight($match->allowedMethods());
    }

    return $handler->handle($request);
}
```

A middleware that passes the request on with another method or path — a method override, a
stripped locale prefix — gets it routed as passed on: the route is looked up again for
everything further in (the middleware added after it, the error handler, the route). The
middleware itself and those added before it have seen the `RouteMatch` of the request as
it came in — add a rewriting middleware first if an access log or a guard is to see the
route that runs.

### Error Handler

```php
use Sodaho\Router\RouteMatch;

$router->setErrorHandler(function (Throwable $e, ServerRequestInterface $request): ?ResponseInterface {
    $format = $request->getAttribute(RouteMatch::class)?->route?->getAttribute('format');

    return $format === 'oauth'
        ? Response::json(['error' => 'server_error'], 500)
        : null;                                             // null: the router's own 500
});
```

It is called for what route middleware and handlers throw — not for a 500 a handler
returns. Its response passes through the middleware for every request like any other. The
`error` hook fires in either case. `handle()` does not throw for any of this: an error
handler that throws itself counts as `null` (and is reported through the `error` hook,
unless it only hands the exception back). What a middleware for every request throws goes
to the same error handler as the last resort — with the request as far as it came; that
response no longer passes through the middleware. (What still leaves `handle()`, as
before: a responder set with `Response::setResponder()` that throws while the router's own
500 is built.)

From the outside in: middleware for every request → error handler → 404/405, or route
middleware → handler.

## Looking a Route Up

```php
$match = $router->match($request);     // nothing runs: no middleware, no handler, no routing hook

$match->status;            // RouteMatch::FOUND | NOT_FOUND | METHOD_NOT_ALLOWED
$match->route;             // the Route; at METHOD_NOT_ALLOWED a route of the path (see below)
$match->params;            // ['id' => '5'] — as in the path, not cast yet
$match->allowedMethods();  // every method the path is registered with
$match->path;              // the path the table was asked with (decoded, without basePath)
```

`match()` needs no container, so it works before the application is booted;
`setContainer()`, `middleware()`, `setErrorHandler()` and hooks added afterwards take
effect as usual. Finish the rest — base path, trailing slash mode, debug, routes — before
the first use (`match()`, `handle()` or `url()`, whichever comes first): what the routing
works with is taken at that moment.

Every request that goes through `handle()` carries the result as attribute
`RouteMatch::class`, and on a hit the route as `Route::class`. Hand the request on with the
`RouteMatch` you already have and `handle()` does not look it up again — unless method or
path changed since. Only a `RouteMatch` this router made is taken over.

At `METHOD_NOT_ALLOWED` — which is what a CORS preflight is for a path without an OPTIONS
route — `route` is a route registered for that path: the GET route if there is one,
otherwise that of the first allowed method. Tell the cases apart by `status`.

## Named Routes & URL Generation

```php
$r->get('/users/{id}', [UserController::class, 'show'])
    ->name('user.show');

// Generate URL
$url = $router->url('user.show', ['id' => 5]);
// → /users/5

// Absolute URL (needs 'baseUrl' in the config, or APP_URL with Router::fromEnv())
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
Response::download($content, 'file.pdf');                // Attachment (in-memory string, filename sanitized)
Response::download($content, 'file.pdf', 'application/pdf');
Response::download($png, 'avatar.png', 'image/png', inline: true);  // shown, not saved
Response::file('/path/to/file.pdf', 'file.pdf');         // Streamed attachment
Response::json(['access_token' => $token]);              // JSON as given, no envelope
Response::json(['error' => 'invalid_request'], 400);
```

### Large Files

`download()` takes the whole body as a string — fine for generated content, but a file of
size N costs roughly N bytes of memory (plus the copy the emitter used to make). Use
`file()` for anything that can grow: the body is a `FileStream`, the emitter pulls it in
8 KB chunks (the default, see `emitChunkSize`), and peak memory stays flat no matter how large the file is.

```php
// Full file, forced download
Response::file($path, 'invoice.pdf', 'application/pdf');

// Inline preview (images, PDF, audio, video)
Response::file($path, 'clip.mp4', 'video/mp4', inline: true);

// Single HTTP Range (206) — what <audio>/<video> use for seeking.
// Pass the raw Range header; unsatisfiable ranges answer 416 automatically,
// anything unparseable falls back to the full 200 response.
Response::file($path, 'clip.mp4', 'video/mp4', inline: true, range: $request->getHeaderLine('Range') ?: null);

// Optional cap for a single 206 body (clients fetch the rest with follow-up ranges)
Response::file($path, 'clip.mp4', 'video/mp4', inline: true, range: $range, maxChunk: 1024 * 1024);
```

`file()` sets `Content-Length`, `Accept-Ranges: bytes` and `X-Content-Type-Options: nosniff`,
and throws `RouterException` when the path is not a readable file, or when `maxChunk` is below
1 — check existence first and answer `Response::notFound()` yourself if you want a 404 instead
of a 500. `$path` is opened as given: build it from your own storage layout, never from
request input.

### Filenames

Both `file()` and `download()` treat the filename as untrusted input — it usually comes from
an upload:

- Control characters are dropped (a raw `\r\n` would make PSR-7 reject the header and kill
  the response), as are bidi controls (overrides, isolates and the marks `U+200E`, `U+200F`,
  `U+061C`) — `U+202E` turns `Rechnung‮fdp.exe` into a disguised
  `.exe` in the download dialog.
- `/` and `\` are replaced with `_`; surrounding whitespace is trimmed; `.` and `..` become
  `download`.
- Values longer than 200 bytes are truncated, keeping the file extension.
- The `filename="…"` form carries **ASCII only** — every other character becomes `_`. The
  original name is sent in the RFC 5987 `filename*=UTF-8''…` form, which clients prefer.
  Anything that is not valid UTF-8 gets no extended form at all: declaring `UTF-8''` and
  then sending other octets would be a lie.

So a plain ASCII name without separators stays exactly as it was; anything else is normalized.

It does **not** send `ETag`/`Last-Modified` and ignores `If-Range` — if files can be replaced
under the same path, a resumed download may mix two versions.

**Uploads are not affected by this** — they never pass through `Response`. Incoming request
size stays a matter of `upload_max_filesize` / `post_max_size` (PHP) and
`client_max_body_size` (nginx).

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

Only what you pass counts: `Router::create()` and `new Router()` do not look at the
environment.

### Via Environment Variables

```php
$router = Router::fromEnv();                       // everything from the environment
$router = Router::fromEnv(['debug' => false]);     // a key you pass wins over its variable
```

`fromEnv()` is the one place where the router reads the environment (`$_ENV`, then
`getenv()`), and these are all the variables it reads:

```php
// .env
APP_DEBUG=true
APP_URL=https://api.example.com
ROUTER_BASE_PATH=/api
ROUTER_TRAILING_SLASH=ignore
ROUTER_URL_ENCODING=true
```

A key in the array you pass wins whatever its value — `null`, `false` and `''` included
(`null` then means the default) — and its variable is not looked at. `APP_DEBUG` and
`ROUTER_URL_ENCODING` have to be boolean-like (`true`/`false`, `1`/`0`, `on`/`off`,
`yes`/`no`) or empty; anything else makes `fromEnv()` throw a `RouterException` that names
the variable. `APP_ENV` means nothing to the router.

### Via Fluent API

```php
$router = Router::create()
    ->setDebug(true)
    ->setBasePath('/api');

$router->isDebug();   // what the router decided
```

### Options

| Config Key | Variable read by `fromEnv()` | Default | Description |
|------------|--------------|---------|-------------|
| `debug` | `APP_DEBUG` | `false` | Enable debug mode (detailed errors). Boolean or boolean-like (`'true'`, `'0'`, ...). `null` means the default; `''` and `0` count as off; anything else is refused |
| `basePath` | `ROUTER_BASE_PATH` | `''` | URL prefix for all routes (`/api`, `/api/` and `api` mean the same) |
| `baseUrl` | `APP_URL` | `null` | Base URL for `absoluteUrl()` |
| `trailingSlash` | `ROUTER_TRAILING_SLASH` | `'strict'` | `'strict'` or `'ignore'` |
| `urlEncoding` | `ROUTER_URL_ENCODING` | `true` | `rawurlencode()` parameter values in `url()`/`absoluteUrl()`; `false` inserts them as given. Boolean or boolean-like, as `debug` |
| `routesFile` | - | `null` | Routes file, as `loadRoutes()` sets it |
| `implicitHead` | - | `true` | Answer `HEAD` through the `GET` route (see [HTTP Methods](#http-methods)) |
| `emitChunkSize` | - | `8192` | Bytes `run()`/`emit()` read from the response body at a time; an integer, or a string of digits, from 1024 to 16777216 (see [Memory](#memory)) |

## Hooks (Logging)

```php
// Log successful dispatches
$router->on('dispatch', function (array $data) {
    // $data: method, path, route, handler, params, duration
    // (duration: from the start of handle(), so it includes the way in through the
    // middleware for every request)
    $logger->info("Route matched", $data);
});

// Log 404 errors
$router->on('notFound', function (array $data) {
    // $data: method, path
    $logger->warning("404", $data);
});

// Log 405 errors
$router->on('methodNotAllowed', function (array $data) {
    // $data: method, path, allowed_methods — a list of every method registered for the path:
    // methods of static routes first, then of dynamic ones; within each group in the order in
    // which a method first occurs among the routes of that group. The 405 response carries the
    // same list in `Allow` and in `error.details.allowed`.
    $logger->warning("405", $data);
});

// Log exceptions
$router->on('error', function (array $data) {
    // $data: method, path, exception — or type ('emit'), message, exception
    $logger->error("Error", $data);
});
```

**Note:** Hook exceptions are caught and never affect the response. Register `hookError` to
get them; without it a line goes to stderr (`error_log()` where there is none). A
`hookError` callback that fails itself gets that line too.

```php
$router->on('hookError', function (array $data) {
    // $data: event (the hook that failed), exception
    $logger->error("Hook failed", $data);
});
```

**Without an `error` hook nothing is logged.** An exception caught by the router becomes a
500 response and leaves no other trace — logging is the application's job, the hook is how.

**`path` and `params` are request data.** In `dispatch`, `notFound` and `methodNotAllowed`
they are already URL-decoded — `/x%0Ay` arrives with a real line break in it. Encode them
before they go into a line-based log.

## PSR-15 Compatibility

```php
// run() for simple apps
$router->run();

// handle() for PSR-15 integration
$request = $serverRequestFactory->fromGlobals();
$response = $router->handle($request);  // Returns ResponseInterface

// Emit it — with the router's emitter or any other PSR-7 emitter
$router->emit($response);
$router->emit($response, withBody: $request->getMethod() !== 'HEAD');
```

What `run()` and `emit()` do with headers the host application set before them: a field that exists once
per message (`Content-Type`, `Location`, `Content-Length`, `ETag`, ...) is replaced by the
response's value; every other field is a list and the response's lines are added — a
`Vary: Cookie` or the `Cache-Control: no-store` of `session_start()` stays in place; so does
every field the router does not know. `X-Frame-Options`, `Strict-Transport-Security` and
`Access-Control-Allow-Origin` are added as well, not replaced — set them in one place. The
status is the response's, whatever its headers are (PHP would turn a 403 with
`WWW-Authenticate` into a 401) — with one exception kept for 1.x: a 200 that carries a
`Location` goes out as the redirect PHP has always made of it. If output has already
started, nothing can be sent any more: the `error` hook is called with `type: 'emit'`.

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

try {
    $url = $router->url('users.show', ['id' => 5]);
} catch (RouterException $e) {
    // Catches all router exceptions
    echo $e->getMessage();
    echo $e->getDebugMessage();  // Additional debug info
}
```

Whatever is thrown while a request is handled — by a handler, a middleware or the router
itself — never leaves `handle()`: it becomes a 500 response and is passed to the `error`
hook. That includes `NotFoundException` and `MethodNotAllowedException` thrown by your own
code; return `Response::notFound()` instead. The one thing that does leave `handle()`: what
a responder set with `Response::setResponder()` throws while that 500 is built. `run()` adds one case of its own: a response
whose body was closed before it could be sent raises `RouterException`.

| Exception | When |
|-----------|------|
| `NotFoundException` | Never thrown by the router (it answers 404 itself); for your own code |
| `MethodNotAllowedException` | Never thrown by the router (it answers 405 itself); for your own code |
| `RouteNotFoundException` | Named route doesn't exist (URL generation) |
| `DuplicateRouteException` | Same method+pattern registered twice |

## Trailing Slash Handling

```php
// Default: strict (exact match)
$r->get('/users', $handler);   // Only matches /users
$r->get('/users/', $handler);  // Only matches /users/

// Ignore mode: /users matches both /users and /users/
$router = Router::create(['trailingSlash' => 'ignore']);
```

## Serving a Web App

A built front end — `index.html` plus assets — in one line per app:

```php
$router->app('/login', __DIR__ . '/../login/dist');
$router->app('/', __DIR__ . '/../site/dist');        // the root works as well
```

Routes always come first. Where no route matches a `GET` or `HEAD` request under the
prefix, the router sends

- the file, if the folder has one for that path (with its `Content-Type`,
  `X-Content-Type-Options: nosniff`, and `Range` support);
- otherwise the start page — the app's own router takes over from there —
- unless the path looks like a file (a dot in its last segment): a missing
  `/login/assets/app.js` is a 404, not HTML that the browser would try to run.

Two consequences of that rule for the app's own routes:

- A route whose last segment contains a dot is a 404 on a full page load:
  `/login/user/john.doe`, `/login/invite/a@b.com`. (`/login/v1.2/page` is fine — only the
  last segment counts.) Keep dots out of the last segment or give such paths a route.
- Every other unknown `GET` path under the prefix is answered with the start page and
  status 200 — a mistyped `/login/api/statsu` included. The app shows its own "not
  found" there; an API client gets HTML.

The prefix is relative to the base path, the most specific prefix decides alone (`/login`
before `/`), and a path that the route table knows for another method stays a 405. The
router still needs its routes file (`loadRoutes()`), also when it serves folders only. A
relative folder means the working directory at the time of the call.

**Never served**, whatever the folder contains: anything outside it (the resolved file has
to lie under the resolved folder — links are followed and checked), hidden files and
folders (a leading dot, `.well-known` included — give those a route), files that cannot be
read, file types that are not on the list (see `AppFolder::TYPES`, extend it with the
`types` option), and paths with a NUL byte, a backslash, an encoded separator (`%2F`,
`%5C`), an empty segment (`//`) or a segment that ends in a dot or a space. A requested
path with a colon below the prefix is never looked up as a file (on Windows it would name
a stream of one); as a path of the app's own router — `/login/item/urn:isbn:1` — it gets
the start page. (Most PSR-7 implementations fold slashes at the very start of a path into
one before the router sees it: `//login/x` is `/login/x` then, for routes and apps alike.)

PHP sources (`php`, `phtml`, `phar`, `inc`, …) cannot be put on the list. Source maps
(`.map`) are not on it: they publish the sources of the app —
`'types' => ['map' => 'application/json']` if that is what you want.

The folder you register is trusted as a whole: the rule for hidden names applies below
it, not to its own path, and a folder that is a link is followed (deployments switch
releases that way). Whoever can write into the folder, replace it, or rename a directory
above it decides what is served — keep all of that writable for the deployment only.

**Caching:** the start page goes out with `Cache-Control: no-cache`, and so does every
other file — until you say which files never change. The router does not guess: a file
that is wrongly cached for a year cannot be called back.

```php
use Sodaho\Router\AppFolder;

$router->app('/login', $dir, ['immutable' => AppFolder::HASHED]);
```

`AppFolder::HASHED` is the rule for what the common bundlers write. A requested path
counts when

- it lies in `assets/` (Vite, Rollup, esbuild) or `static/` (webpack, Create React App),
  at any depth, **and**
- its name ends, before the extension, in a hyphen and exactly eight characters out of
  `A-Z a-z 0-9 _ -` (`assets/index-B1fQx9cD.css`) or in a dot and 8 to 32 hexadecimal
  digits in lower case (`assets/app.4f9a2b1c.js`, `static/js/main.a1b2c3d4.chunk.js`).

Such files go out with `public, max-age=31536000, immutable`. The rule goes by form, and
a form proves nothing: `assets/app-settings.js` and `assets/user-12345678.png` have it
too. Pass the constant where these two directories hold nothing but the bundler's output.
Whatever a build copies unchanged does not belong there, with any bundler: nothing in
Vite's `public/assets/` or Create React App's `public/static/`
(`static/fonts/Poppins-SemiBold.woff2` has the form as well) — and do not pass it for an
Angular build, where `assets/` is the directory that is copied as it is.
`apple-touch-icon-180x180.png` next to `index.html` never counts.

For a build that hashes differently (Angular, a custom output directory) `immutable` takes
a regular expression of your own; it is matched against the requested path below the
prefix — what the browser caches by, not the file a link leads to. The start page keeps
its own rule under every name that leads to it. Conditional requests (`ETag`,
`If-Modified-Since`) are not answered yet, so `no-cache` means the file is sent again on
every load.

```php
$router->app('/login', $dir, [
    'index' => 'index.html',                  // name of the start page
    'types' => ['md' => 'text/markdown; charset=utf-8', 'pdf' => null],   // add / take off the list
    'immutable' => '~^[^/]+[.-][0-9a-f]{16}\.(?:js|css)$~',   // which paths never change; default null: none
    'cacheIndex' => 'no-cache',
    'cacheImmutable' => 'public, max-age=31536000, immutable',
    'cacheOther' => 'max-age=300',            // null: send no Cache-Control
]);
```

[Middleware for every request](#middleware-for-every-request) runs before — protect an app
or add headers there. For the route table an app path is a path without a route, so the
request carries a `RouteMatch` with status `NOT_FOUND`. **A middleware that answers
`NOT_FOUND` itself gets there first:** leave the app's paths alone.

```php
public function process($request, $handler): ResponseInterface
{
    $match = $request->getAttribute(RouteMatch::class);
    $underAnApp = str_starts_with($match->path . '/', '/login/');

    if ($match->status === RouteMatch::NOT_FOUND && !$underAnApp) {
        return Response::html($this->notFoundPage, 404);
    }

    return $handler->handle($request);          // route, app folder, or the router's own 404
}
```

`$match->path` is the path without the base path — except for a request outside the base
path, where it is the whole path: with a base path, compare against the request's own
path instead. And with an app at `/` every `GET` path belongs to an app; there is nothing
left for such a middleware to answer.

To serve the start page from a route of your own instead, see the next section.

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
    // `$any` mirrors the {any:any} placeholder — every placeholder of the route must be
    // declared, even when the handler ignores it.
    public function index(ServerRequestInterface $request, string $any): ResponseInterface
    {
        return Response::html(file_get_contents('public/index.html'));
    }
}
```

## Quick Boot

```php
// One-liner for simple apps
Router::boot(['debug' => true], __DIR__ . '/routes.php');

// The same with the configuration from the environment
Router::fromEnv()->loadRoutes(__DIR__ . '/routes.php')->run();
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

### Building the Route Table

The table is built from the routes file once per router — under PHP-FPM that is once per
request; with OPcache on that is cheap:

| Routes | Time |
|--------|------|
| 114 | 0.11 ms |
| 500 | 0.47 ms |
| 1000 | 0.92 ms |

One request with a fresh router and a hit on a dynamic route; PHP 8.5, Linux arm64, OPcache
on. (1.x had a route cache; measured, loading it took about four times as long as this, so
2.0 has none.)

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

- ~1KB per route in memory
- 100 routes ≈ 100KB memory footprint
- `emitChunkSize` rarely needs a change: with the default 8 KB the emit loop moves about
  2.5 GB per second (1 GiB file, PHP 8.5, output discarded). 64 KB to 1 MB roughly halves
  the time the loop takes for large files on a fast network; above 1 MB it gets slower
  again, and every running request holds two to three chunks in memory.
- Response bodies are emitted in chunks of 8 KB by default — a `Response::file()` download of any size
  keeps peak memory flat (a 32 MB file cost ~96 MB before that change)

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
```

Debug is off unless you switch it on: with `'debug' => true`, or with `APP_DEBUG` through
`Router::fromEnv()`. To keep it off regardless of the environment, pass `'debug' => false`
— a key you pass always wins.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Requirements

- PHP ^8.5
- PSR-7 HTTP Message (nyholm/psr7)
- PSR-15 HTTP Handler/Middleware

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).

## License

MIT
