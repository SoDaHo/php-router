# sodaho/php-router

PSR-7/PSR-15 router: routes, groups, middleware, named routes, JSON response helpers and a response emitter.

## Requirements

- PHP ^8.5 (`Uri\WhatWg\Url` checks the base URL)
- psr/http-message ^2.0, psr/http-server-handler ^1.0, psr/http-server-middleware ^1.0, psr/container ^2.0,
  psr/http-factory ^1.1, nyholm/psr7 ^1.8.2, nyholm/psr7-server ^1.1

## Installation

```bash
composer require sodaho/php-router
```

## Quick start

```php
// public/index.php
Sodaho\Router\Router::create()->loadRoutes(__DIR__ . '/../routes.php')->run();

// routes.php
use Sodaho\Router\{Response, RouteCollector};
return function (RouteCollector $r) {
    $r->get('/users/{id:int}', fn ($request, int $id) => Response::success(['id' => $id]))->name('user.show');
    $r->post('/users', [UserController::class, 'store']);
};
```

## Reference

### Router (`Sodaho\Router\Router`, implements `Contract\RouterInterface`)

| Signature | Description |
|---|---|
| `__construct(array $config = [])` / `static create(array $config = []): self` | [Configuration](#configuration). |
| `static fromEnv(array $config = []): self` | Adds the variables of [Configuration](#configuration). |
| `static boot(array $config, string $routesFile): void` | `create()`, `loadRoutes()`, `run()`. |
| `loadRoutes(string $file): self` | File returning `function (RouteCollector $r) {…}`. |
| `setContainer(ContainerInterface $container): self` | PSR-11 container for classes by name. |
| `setDebug(bool $debug): self` / `isDebug(): bool` | Debug mode, see [Security](#security). |
| `setBasePath(string $basePath): self` / `setBaseUrl(?string $baseUrl): self` | See [Configuration](#configuration). |
| `middleware(string\|array\|object $middleware): self` | Middleware for every request, first = outermost. |
| `app(string $prefix, string $directory, array $options = []): self` | Web app folder, see [AppFolder](#appfolder). |
| `setErrorHandler(callable $handler): self` / `on(string $event, callable $callback): static` | See below / Hooks. |
| `match(ServerRequestInterface $request): RouteMatch` | Looks the request up, runs nothing. |
| `handle(ServerRequestInterface $request): ResponseInterface` | PSR-15; does not throw. |
| `url(string $name, array $params = []): string` / `absoluteUrl(…)` | See [UrlGenerator](#urlgenerator). |
| `run(): void` | Request from PHP's globals, `handle()`, send. |
| `emit(ResponseInterface $response, bool $withBody = true): void` | See [run() and emit()](#run-and-emit). |
| `__clone()` | Throws `RouterException` after the first use. |

- The route table is built by the first request, `match()`, `url()` or `absoluteUrl()`. After that, `setDebug()`,
  `setBasePath()`, `setBaseUrl()`, `loadRoutes()` and `clone` throw `RouterException`; `setContainer()`,
  `middleware()`, `app()`, `on()` and `setErrorHandler()` take effect for later requests.
- The routes file is required once per `loadRoutes()`; its closure sees the router as `$this`. When the table cannot
  be built (no routes file set or found, the file threw or returned no callable, the callable threw, a route was
  refused), `match()` and `url()` throw, and each request goes to the error handler (default: the router's 500) and
  to `error`. What the callable registered on the router (middleware, apps, hooks) is taken back.
- The error handler, `fn (Throwable $e, ServerRequestInterface $request): ?ResponseInterface`, answers what route
  middleware, handlers and middleware for every request throw; `null` or a throw gives the router's 500.
- Order, outside in: middleware for every request, error handler, then 404, 405, 400 or the route middleware (groups
  outer to inner, then the route's own) and the handler.
- Request attributes: `RouteMatch::class` on every request, `Route::class` on a hit, `_route_params` and each
  parameter under its own name. A middleware that passes the request on with another method or path has the route
  looked up again for everything further in.

### RouteCollector (`$r` in the routes file)

| Signature | Description |
|---|---|
| `get(string $pattern, mixed $handler): Route` | Also `post()` `put()` `patch()` `delete()` `options()` `head()` |
| `match(array $methods, string $pattern, mixed $handler): Route` | RFC 9110 tokens, upper-cased. |
| `any(string $pattern, mixed $handler): Route` | GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD. |
| `redirect(string $from, string $to, int $status = 302): Route` | GET and HEAD, a `RedirectHandler`. |
| `group(string $prefix, callable $callback): void` | Prefix for the routes in `$callback`. |
| `middlewareGroup(string\|array\|object $middleware, callable $callback, array $attributes = []): void` | Middleware. |
| `attributeGroup(array $attributes, callable $callback): void` | Inner wins per key, also in `middlewareGroup()`. |
| `addPattern(string $name, string $regex): self` / `addPatterns(array $patterns): self` | Type for `{x:name}`. |
| `setPreserveTrailingSlash(bool $preserve): self` | `true`: mode `strict` (set by the router). |
| `getRoutes(): array` / `getPatterns(): array` | `Route` objects; types as `name => regex`. |
| `getData(): array` | `[static routes, dynamic routes]`; freezes the routes. |

A handler is `[Controller::class, 'method']` (the container's entry, else `new` when the constructor needs no
argument), a callable (`[$object, 'method']`, a closure, an invokable object) or a `RequestHandlerInterface`.
Controllers and callables get the request first and each placeholder as a named argument; a `RequestHandlerInterface`
gets the request alone. A handler returns a `ResponseInterface`. A container entry that is no object of the named
class (for middleware: no `MiddlewareInterface`) is a 500.

| Type | Regular expression | Cast (failure: 400 `INVALID_PARAMETER`) |
|---|---|---|
| none: `{id}` | `[^/]+` | string |
| `int` | `-?\d+` | int; `01`, `-0` and overflow fail |
| `float` | `-?\d+(?:\.\d+)?` | float; overflow fails |
| `bool` | `true\|false\|0\|1`, any case | bool |
| `alpha`, `alphanum`, `slug` | `[a-zA-Z]+`, `[a-zA-Z0-9]+`, `[a-z0-9-]+` | string |
| `uuid`, `ulid` | 8-4-4-4-12 hex digits; 26 of `[0-9A-Za-z]` | string |
| `any` | `(?:[^/]+(?:/[^/]+)*(?:/(?=\z))?)?`: slashes, no empty segment | string |

Refused with `RouterException` where the route is written:
- A placeholder other than `{name}` or `{name:type}`; a name that does not begin with an ASCII letter or `_`, is
  longer than 32 characters, is `_route_params` or stands twice in the pattern; `[` or `]`.
- A pattern (and a `basePath`) that is no plain path written decoded: backslash, control character, `%XX`, `?`, `#`,
  `.` or `..` segment.
- An empty method list, a method that is no token; method and pattern taken (`DuplicateRouteException`).
- `addPattern()`: a name other than `[A-Za-z0-9_]+`; a fragment with an unescaped `#`, a named group, a `(*…)` verb,
  a callout `(?C…)`, or an option that turns on `x` or `xx`.
- When the table is built: an undefined type, an own fragment that does not compile or closes its group, two routes
  of different patterns under one name (`DuplicateRouteException`, also from `new UrlGenerator()`).

Matching: static routes (no placeholder) first, then dynamic routes in order of definition. A request path with
`%2F`, `%5C`, a backslash, a control character or a `.`/`..` segment (also encoded) is a 404 before the table is
asked. A 500 (reported to `error`): a parameter named like an attribute the request carries; a route expression PCRE
gives up on (backtrack limit, JIT stack); a placeholder named like the first parameter of a controller or callable.

Trailing slash mode `strict` (default): `/users` and `/users/` are two routes; in `group('/api')`, `get('')` is
`/api` and `get('/')` is `/api/`. Mode `ignore`: trailing slashes of routes and requests are dropped (`/` stays).

### Route

Built by the collector: `__construct(array $methods, string $pattern, mixed $handler, array $middleware = [],
?string $name = null, array $attributes = [])`.

| Signature | Description |
|---|---|
| Readonly: `array $methods`, `string $pattern`, `mixed $handler` | Set by the collector. |
| `array $middleware`, `?string $name`, `array $attributes` | Assignable until the table is built. |
| `middleware(string\|array\|object $middleware): self` | Appends middleware. |
| `name(string $name): self` / `attribute(string $key, mixed $value): self` | Name for `url()`; attribute. |
| `getAttribute(string $key, mixed $default = null): mixed` | Reads an attribute. |

- A string key names one middleware: the same key again in a route and its groups, or in the router's own list, is a
  `RouterException`. A route attribute wins over its groups.
- Once the table is built, `middleware()`, `name()`, `attribute()` and assigning `$middleware`, `$name` or
  `$attributes` throw `RouterException`; writing into an array in place (`$route->attributes['k'] = …`) is an `Error`.

### RouteMatch

| Signature | Description |
|---|---|
| `const FOUND`, `NOT_FOUND`, `METHOD_NOT_ALLOWED` | Values of `$status`. |
| Readonly: `int $status`, `string $method`, `string $path` | `$path`: see below. |
| Readonly: `?Route $route`, `array $params`, `array $casts`, `bool $viaGet` | `$params` not cast; HEAD via GET. |
| `isFound(): bool` / `allowedMethods(): array` | Status `FOUND` / methods of the path, HEAD behind GET. |

`$path` is the path the table was asked with (decoded, no base path); where the table was not asked, the request
path (decoded outside the base path, as sent for a path refused before the table). At `METHOD_NOT_ALLOWED`, `$route`
is the path's GET route, else that of the first allowed method: its attributes are not those of the method asked
for. `handle()` takes over a `RouteMatch` this router made for the same method and path; any other is looked up again.

### Response (`Sodaho\Router\Response`, static)

| Signature | Status |
|---|---|
| `success(mixed $data, ?string $message = null, ?array $meta = null)` | 200 |
| `created(mixed $data, ?string $message = null, ?string $location = null)` | 201, `Location` |
| `accepted(mixed $data, ?string $message = null)` / `noContent()` | 202 / 204 without body |
| `paginated(array $items, int $total, int $page, int $perPage)` | 200, `meta.pagination` |
| `error(string $message, int $status = 400, ?string $code = null, ?array $details = null)` | `$status` |
| `notFound(?string $resource = null, string\|int\|null $identifier = null)` | 404 |
| `unauthorized(?string $message = null)` / `forbidden(?string $message = null)` | 401 / 403 |
| `validationError(array $errors)` / `methodNotAllowed(array $allowedMethods)` | 422 / 405 with `Allow` |
| `tooManyRequests(int $retryAfter)` / `serverError(?string $message = null, ?array $debug = null)` | 429 / 500 |
| `html(string $content, int $status = 200)` / `text(string $content, int $status = 200)` | HTML / text, UTF-8 |
| `json(mixed $data, int $status = 200, string $contentType = 'application/json')` | `$data`, no envelope |
| `redirect(string $url, int $status = 302)` | `REDIRECT_STATUSES`: 301, 302, 303, 307, 308 |
| `setResponder(ResponderInterface $responder): void` / `reset(): void` | Set the responder / `JsonResponder` |
| `getResponder(): ResponderInterface` | Current responder |

```php
download(string $content, string $filename, string $contentType = 'application/octet-stream', bool $inline = false)
file(string $path, ?string $filename = null, string $contentType = 'application/octet-stream', bool $inline = false,
    ?string $range = null, ?int $maxChunk = null)   // 200, 206 or 416
```

- Each helper returns a `ResponseInterface`. `tooManyRequests()` below 0 and `redirect()` with another status
  throw `RouterException`; `paginated()` throws `InvalidArgumentException` for `$page` or `$perPage` below 1, `$total`
  below 0, a last item beyond `PHP_INT_MAX`.
- `file()`: 200 or 206 with a `FileStream`, `Content-Length`, `Accept-Ranges: bytes`, `X-Content-Type-Options:
  nosniff`, length and range from the opened file; 416 with an empty body, `Content-Range: bytes */<size>`,
  `Accept-Ranges` and `Content-Length: 0`. `$range` is the raw `Range` header: one range `bytes=a-b`, `a-` or `-n`
  gives 206, an unsatisfiable one 416, anything else 200. `$maxChunk` caps a 206 body. A path that is no readable
  file and `$maxChunk` below 1 throw `RouterException`. No `ETag`; `If-Range` is not looked at.
- Filenames of `download()` and `file()`: control and bidi characters dropped, `/` and `\` become `_`, 200 bytes at
  most (an extension of up to 16 bytes with its dot kept), `.`, `..`, empty: `download`; ASCII `filename="…"`, valid
  UTF-8 also `filename*=UTF-8''…`.

| Responder (`Service\…`; body: the array as JSON) | Success | Error (4xx/5xx) |
|---|---|---|
| `JsonResponder` | `{"success": true, "data", "message"?, "meta"?}` | `{"success": false, "message", "error": {…}}` |
| `RfcResponder(string $typeBaseUri = 'about:blank')` | `{"data", "message"?, "meta"?}` | RFC 9457 problem+json |

`error` of `JsonResponder` holds `message`, `code`, `details`. `RfcResponder`: `type` from the code (`NOT_FOUND` →
`<base>/not-found`; `about:blank` without code or with the base `about:blank`, the default), `title` from the message,
`status` of the response, `detail`, `instance` and other keys from the details; `type`, `title`, `status` of the
details are dropped.

`ResponderInterface`: `formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array`,
`formatError(string $message, ?string $code = null, ?array $details = null): array`, `getContentType(): string`
(4xx/5xx) and `getSuccessContentType(): string` (2xx/3xx).

### UrlGenerator

| Signature | Description |
|---|---|
| `__construct(array $routes = [], ?array $patterns = null)` | Routes; `$patterns` of `getPatterns()`. |
| `setBasePath(string $basePath): void` | Put in front of every address. |
| `setBaseUrl(?string $baseUrl): void` | `null`, `''`: none; else the rule of `baseUrl`. |
| `setIgnoreTrailingSlash(bool $ignore): void` | The router's mode `ignore`. |
| `setEncodeParams(bool $encode): void` | Deprecated since 2.2.0; `false` throws. |
| `url(string $name, array $params = []): string` | Relative address. |
| `absoluteUrl(string $name, array $params = []): string` | Base URL and `url()`. |
| `hasRoute(string $name): bool` | Whether a route has the name. |

- `url()` encodes values with `rawurlencode()` — a slash the placeholder takes stays, each segment encoded — and the
  literal text of pattern and base path (`:`, `@`, `!$&'()*+,;=` stay). Parameters that are no placeholders are left
  out (no query string). Not checked: whether another route takes the address first.
- `url()` throws `RouterException` for a missing or `null` parameter, a float that is not finite, a value with a
  backslash or control character, values with which the address would not match the route or not pass its cast
  (`12a` or `01` for `{id:int}`, an empty segment in `{x:any}`), a `.` or `..` segment in the address, an address
  that begins with `//`. `absoluteUrl()` without base URL: `RouterException`. Unknown name: `RouteNotFoundException`.

### AppFolder

| Signature | Description |
|---|---|
| `__construct(string $prefix, string $directory, array $options = [])` | Built by `app()`; options below. |
| `readonly string $prefix`, `const TYPES`, `const HASHED` | Prefix (`''`: root); extension => type; bundler regex. |
| `owns(string $path): bool` | Whether the path lies under the prefix. |
| `serve(ServerRequestInterface $request, string $path): ?ResponseInterface` | The file, the start page or `null`. |

- Asked where the route table has no route for the path (`NOT_FOUND`); a path the table knows for another method
  stays a 405. A GET or HEAD under the prefix gets the folder's file, otherwise the start page; a 404 where the last
  segment has a dot. The longest prefix decides; prefixes are relative to the base path.
- Not served: files outside the resolved folder, hidden names (leading dot), unreadable files, extensions not in
  `types`; paths with a control character, a backslash, `%2F`, `%5C`, `//`, or a segment that ends in a dot or
  space. A path with a colon is not looked up as a file.
- The file is opened, then checked at the handle (resolved path, device and file number); `ETag`, length and body
  come from that handle. The checks assume a folder writable for the deployment alone: a directory on the way
  swapped for a link and back between two checks is not detected, and a hard link is served as the file it names.
- Files go out without `Content-Disposition`, with `nosniff` and the options' `Cache-Control`. `ETag`: a hash of the
  content up to 64 KiB, above `W/` device, file number, time, size (none without file numbers). An `If-None-Match`
  with the file's tag, or `*`, gives 304; a `Range` counts for GET without `If-Range`.

### RedirectHandler (`Middleware\RedirectHandler`)

| Signature | Description |
|---|---|
| `__construct(string $target, int $status = 302)` | Target with `{name}` placeholders. |
| `handle(ServerRequestInterface $request): ResponseInterface` | Fills them from `_route_params`. |
| `getTarget(): string` / `getStatus(): int` | Target and status as given. |

- Refused by the constructor: a control character other than a tab, a placeholder that is not `{name}`, a status not
  in `Response::REDIRECT_STATUSES`, a placeholder where scheme or host belong (a target with a scheme or `//` writes
  scheme and host before its first placeholder, the host closed by `/`, `?` or `#`). `redirect()` also refuses a
  placeholder that the source pattern lacks.
- `handle()` encodes each value as a whole (`/` becomes `%2F`). It throws `RouterException` for a placeholder without
  value, parameters that are no array of finite scalar values, a rendering that changes scheme or host (an empty
  value before a slash), and a value that makes a `.` or `..` segment.

### Other classes

- `Contract\RouterInterface`: `handle()`, `match()`, `url()`, `absoluteUrl()`; type against it to wrap the router.
- `Traits\HasHooks`: `on(string $event, callable $callback): static` appends a callback; `trigger()` is protected.
- `Middleware\RouteHandler`: `__construct(mixed $handler, ?ContainerInterface $container = null)`, `handle()`.
- `Middleware\MiddlewareHandler`: `__construct(MiddlewareInterface $middleware, RequestHandlerInterface $next)`,
  `handle(ServerRequestInterface $request): ResponseInterface` (`$middleware->process($request, $next)`).
- `Dispatcher` (constants as `RouteMatch`, no path checks): `__construct(array $staticRoutes, array $dynamicRoutes)`,
  `dispatch(string $method, string $uri): array` (`[status, route or methods, params, casts]`),
  `staticRoute(string $method, string $uri): ?Route`, `allowedMethods(string $uri): array`.
- `RouteDispatcher`, the PSR-15 handler the router builds: `__construct(array $dispatchData, ?ContainerInterface
  $container = null, string $basePath = '', string $trailingSlash = 'strict', bool $debug = false)`, `setContainer()`,
  `setMiddleware()`, `setImplicitHead()`, `setApps()`, `setErrorResponder(?Closure)` (without one, exceptions leave
  `handle()`), `match()`, `handle()`.
- `Stream\FileStream`, a read-only PSR-7 stream over a file or a slice: `__construct(string $path, int $start = 0,
  ?int $length = null)` throws `RouterException` for a file it cannot open, size or position; `read()`, `seek()`,
  `tell()`, `rewind()`, `eof()`, `getSize()`, `getContents()`, `__toString()`, `getMetadata()`, `close()`,
  `detach()`, `isReadable()`, `isSeekable()`; `isWritable()` is `false`, `write()` throws; `__destruct()` closes;
  the stream methods throw `RuntimeException`. Classes other than the exceptions are `final`.

### run() and emit()

Status, headers and (with `withBody`) the body size are read before the first byte. Header lines go out first, the
status line last. Fields that exist once per message (`Content-Type`, `Content-Length`, `Location`, `ETag`, …)
replace what the host set; others (`Vary`, `Cache-Control`, `Set-Cookie`, …) are added. The body is read in
`emitChunkSize` chunks.

| Refused | When | `run()` | `emit()` |
|---|---|---|---|
| Body closed or detached; a getter throws | before sending | 500 | throws |
| Status outside 100–599 | before sending | 500 | throws |
| Reason phrase or header value with a control character other than a tab | before sending | 500 | throws |
| Protocol version not `\d` or `\d.\d`; header name not an RFC 9110 token | before sending | 500 | throws |
| `Transfer-Encoding`; `Content-Length` not one value of at most 18 significant digits | before sending | 500 | throws |
| `Content-Length` on 1xx or 204; one other than `0` on 205 | before sending | 500 | throws |
| `withBody` true: a body of size other than 0 (or unknown) on 1xx, 204, 205, 304 | before sending | 500 | throws |
| `withBody` true, a status with content: `Content-Length` above 0, body size 0 | before sending | 500 | throws |
| No byte for `emitIdleTimeout` s; body shorter or longer than `Content-Length` | after headers | reported | throws |

- `run()` reports each case to `error`. Output started before the router: nothing is sent, `error` gets type `emit`.
- The answer to HEAD keeps the GET's `Content-Length` without a body: `run()` sends it without reading the body (also
  for a POST a middleware passed on as HEAD), `emit()` needs `withBody: false`.
- A request the PSR-7 objects refuse (`Host: x:99999999`) gets 400 from `run()`, reported as `RouterException` "The
  request could not be read"; other failures building the request get 500.

## Configuration

| Key | Variable | Default | Allowed | Refused |
|---|---|---|---|---|
| `debug` | `APP_DEBUG` | `false` | bool, bool-like (`'true'`, `'0'`, `'on'`, `'no'`), falsy | other truthy values |
| `basePath` | `ROUTER_BASE_PATH` | `''` | plain path; `null`, `false` | other types; `%XX`, `\`, `?`, `#`, `.`, `..` |
| `baseUrl` | `APP_URL` | `null` | `http(s)://host[:port][/path]` | other forms (user info, `\`, query, blank) |
| `trailingSlash` | `ROUTER_TRAILING_SLASH` | `'strict'` | `'strict'`, `'ignore'`; `null`, `''` | other values |
| `urlEncoding` | `ROUTER_URL_ENCODING` | `true` | values that mean on | off; other truthy values |
| `routesFile` | — | `null` | string | other types |
| `implicitHead` | — | `true` | as `debug` | as `debug` |
| `emitChunkSize` | — | `8192` | int or digit string, 1024 to 16777216 | other values |
| `emitIdleTimeout` | — | `30` | seconds above 0 to 3600; string with 6 decimals at most | other values |

- An unknown key throws `RouterException` naming the known keys. `null` means the default. For `baseUrl`, `null`,
  `''`, `false`, `0` and `'0'` mean none. Variables are read by `fromEnv()` only.
- `fromEnv()` reads `$_ENV`, then `getenv($name, true)`; a key in the array wins, also with `null`, `false` or `''`.
  `APP_DEBUG` and `ROUTER_URL_ENCODING` have to be boolean-like or empty. `APP_ENV` is not read.
- `implicitHead`: HEAD without a HEAD route runs the GET route (method stays `HEAD`), the body is cut, `Allow` lists
  HEAD behind GET. A static GET route wins over a dynamic HEAD route.

| `app()` option | Default | Allowed | Refused |
|---|---|---|---|
| `index` | `'index.html'` | a file name | `/`, `\`, `:`, leading dot, control char, trailing dot or space |
| `types` | `AppFolder::TYPES` | `ext => type`, `null` removes | no map; ext not `[a-z0-9]+`; bad type; PHP sources |
| `immutable` | `null` | regex on the path below the prefix, e.g. `AppFolder::HASHED` | no valid regex |
| `cacheIndex` | `'no-cache'` | Cache-Control of the start page; `null`: none | empty, control char |
| `cacheImmutable` | `'public, max-age=31536000, immutable'` | for paths `immutable` matches | as `cacheIndex` |
| `cacheOther` | `'no-cache'` | for every other file | as `cacheIndex` |

`RouterException` for an unknown option, a missing folder, a prefix in use, and a prefix that is no plain path (a
segment with a leading dot, a separator or control character, a trailing dot or space).

## Hooks

| Event | When | Payload |
|---|---|---|
| `dispatch` | The handler of a route returned | `method`, `path`, `route`, `handler`, `params`, `duration` |
| `notFound` | The router answers 404 | `method`, `path` |
| `methodNotAllowed` | 405 | `method`, `path`, `allowed_methods` |
| `error` | An exception the router answers or reports | `exception`, `method`, `path`, `status` (last key) |
| `error` | `run()`/`emit()` found output started | `type` (`'emit'`), `message`, `exception`, `status` |
| `hookError` | A hook threw | `event`, `exception` |

- `status`: 400 for a parameter that does not cast and a request `run()` cannot read, 500 for other answers of the
  router, the status PHP sent for failures after the headers. A hook that throws does not change the answer; without
  `hookError` a line with event, exception class, file and line (no message) goes to stderr or `error_log()`.

## Exceptions

| Class | When | Members |
|---|---|---|
| `RouterException` | What the router refuses, see above | `getDebugMessage(): ?string` |
| `DuplicateRouteException` | Method and pattern taken; one name on two patterns | |
| `RouteNotFoundException` | `url()`, `absoluteUrl()` with an unknown name | debug message lists the route names |
| `NotFoundException` | For applications; the router answers 404 itself | |
| `MethodNotAllowedException` | For applications; the router answers 405 itself | `getAllowedMethods(): array` |

- All extend `RouterException`, `__construct(string $message = 'Router error', int $code = 0, ?Throwable $previous =
  null, ?string $debugMessage = null)`; `MethodNotAllowedException` defaults `$message` to `'Method not allowed'` and
  takes `array $allowedMethods = []` fifth. The message says what is wrong; `getDebugMessage()` holds the details.
- `handle()` does not throw: what a handler, middleware, the routes file, the error handler, the responder or the
  request object throws becomes a 500 and goes to `error`. Where the responder fails too, the 500 is plain text.

## Security

- Route parameters, hook `path` and `RouteMatch::$path` carry what the client sent: encode them for line-based logs;
  build a file path from `{x:any}` after `realpath()` and a prefix check.
- Debug mode puts exception message, class, file, line and trace into the 500 body. `getDebugMessage()` holds paths,
  patterns and values (`RouteNotFoundException`: the route names): log it, do not send it.
- `run()` takes scheme and host of the request URI from `X-Forwarded-Proto` and `Host` (nyholm/psr7-server): build
  links with `absoluteUrl()` and a configured `baseUrl`.
- Keep identities under namespaced class names (`App\Auth\Identity`): no placeholder name has a `\`.
- With `implicitHead` GET handlers run for HEAD requests (link scanners ask with HEAD).
- `Response::file()` and `app()` open the path they are given: do not build it from request input.
- Not included: a limit on the request line (web server), CSRF, CORS, authentication, rate limits.

## Testing

`composer test` (PHPUnit; suites `test:unit`, `test:integration`, `test:feature`, `test:security`), `composer analyse`
(PHPStan: src level max, tests level 8), `composer cs` (dry run), `composer validate --strict`. No environment
variables; tests start `PHP_BINARY -S 127.0.0.1:<free port>` and `PHP_BINARY -r`.

## Upgrading

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).
