# Changelog

## [Unreleased]

## [1.2.0] - 2026-10-02

### Added
- **Middleware for every request:** `Router::middleware()`. Unlike route middleware it also
  runs for requests that end in 404, 405 or 400 and sees the response made from an
  exception — the place for an access log, security headers or CORS.
- **Error handler:** `Router::setErrorHandler()` builds the response for what route
  middleware and handlers throw; `null` keeps the router's own 500. It runs inside the
  middleware for every request. `handle()` does not throw for a failing error handler.
- **Route lookup without execution:** `Router::match()` returns a `RouteMatch` (status,
  route, raw parameters, `allowedMethods()`), fires no routing hook and needs no container. Every
  request passing through `handle()` carries it as attribute `RouteMatch::class`, and on a
  hit the route as `Route::class` — available to middleware before the handler runs. At 405
  the match names a route of the path. A middleware for every request that changes method
  or path gets the request looked up again for everything further in.
- **Route attributes:** `Route::attribute()`, `Route::getAttribute()`,
  `RouteCollector::attributeGroup()` (nestable; the inner group wins per key, the route
  wins over groups).
- **`implicitHead`** config option (default `false`): HEAD without a route of its own runs
  through the GET route, the response loses its body, `Allow` and `allowedMethods()` name
  HEAD right behind GET. Will be the default in 2.0.
- `hookError` hook: receives event and exception when a hook throws; without it — or when
  it fails itself — the line goes to stderr as before.
- `Router::isDebug()`, `Router::fromEnv()`, `Response::json()` (JSON without the envelope),
  `Response::download(..., inline: true)`, `Dispatcher::allowedMethods()`.
- `Router::emit()` is public.

### Changed
- `Router::setContainer()` reaches a dispatcher that already exists. Until now a container
  set after the first request (or after `url()`) was silently ignored.
- **Subclasses:** the classes got new public methods (`Router`: `middleware`, `match`,
  `setErrorHandler`, `isDebug`, `fromEnv`, `emit`; `RouteDispatcher`: `match`,
  `setContainer`, `setMiddleware`, `setImplicitHead`, `setErrorResponder`; `Route`:
  `attribute`, `getAttribute` and the property `$attributes`; `RouteCollector`:
  `attributeGroup`; `Dispatcher`: `allowedMethods`). A subclass with a member of the same
  name has to be compatible with it; the library itself does not call them from its
  existing code paths. `Router` will be final in 2.0.

### Deprecated
- The route cache (`enableCache()`, `cacheFile`, `cacheSignature`, `ROUTER_CACHE_*`). It
  will be removed in 2.0; measured, it makes requests slower.
- Reading the environment in `Router::create()` and the constructor. Use
  `Router::fromEnv()`; from 2.0 on nothing else looks at the environment, and `APP_ENV` no
  longer switches debug on.

### Fixed
- A `group()` or `middlewareGroup()` whose callback throws no longer stays open: routes
  registered after the exception was caught got its prefix or middleware.

## [1.1.1] - 2026-10-02

### Security
- **The cache file is no longer executed.** It is a signed data file now; the HMAC covers
  every byte that is used. Previously, code placed between the signature line and `return`
  passed verification and was run — and a foreign route table could be slipped in the same
  way. Cache files written by earlier versions are ignored and rewritten.
- The signature is bound to its purpose, so a file signed with the same key for something
  else (another library's cache under a shared `APP_KEY`) is not accepted as route cache.
- An empty cache key is refused like a missing one — it signs nothing. With
  `ROUTER_CACHE_KEY=` left empty the cache is now off (see below) instead of signed with a
  key everybody knows.
- `'debug' => false` in the config array is honoured. With `APP_ENV=local|dev|development`
  it used to be overruled, putting exception message, file and trace into every 500. Any
  value other than `null` now decides — also `0`, `''` and the `false` that
  `getenv('APP_DEBUG')` returns for an unset variable.

### Fixed
- A response that cannot be sent because output had already started is reported through the
  `error` hook (`type: 'emit'`). It used to vanish without a trace.
- 405: the list of allowed methods (hook `allowed_methods`, `error.details.allowed` in the
  body) is always a list. When a static and a dynamic route matched the same path it had
  gaps in its keys and was encoded as a JSON object. Content and order are unchanged.
- Route patterns are anchored with `\z` instead of `$`: `/users/5%0A` no longer matches
  `/users/{id:int}`.
- `float` parameters that overflow answer 400 like `int` ones, not 500.
- `basePath` from the config array and from `ROUTER_BASE_PATH` is normalized like
  `setBasePath()` does it: `/api/` and `api` used to turn every route into a 404.
- A non-boolean `debug` config value (`'false'`, `1`) no longer breaks every request with a
  `TypeError`; boolean-like values are understood, anything else that is not empty throws
  `RouterException` when the router is created.
- Hooks registered after the first request fire for `dispatch`, `notFound` and
  `methodNotAllowed` too.
- `enableCache($file)` without a key keeps the key from the config array or
  `ROUTER_CACHE_KEY` instead of discarding it.
- Filenames: the bidi marks `U+200E`, `U+200F` and `U+061C` are removed like the overrides.
- A middleware class with required constructor parameters and no container entry raises a
  `RouterException` that says so, not an `ArgumentCountError`.
- Routes with objects that have no `__set_state()` (middleware instances) produced a cache
  file that could not be loaded and was rewritten on every request; they are cached now.
- Trouble with the cache no longer turns every request into a 500. A cache file that cannot
  be written, a missing key (`enableCache($file)` in production) and a cache that refers to
  a class the application no longer has are reported through the `error` hook, and the
  request is served from the routes file.

### Changed
Three corrections to what `Router::run()` sends. Applications that emit the response
themselves (`handle()` plus their own emitter) are not affected.
- **Status line is sent after the headers.** PHP rewrites the status when certain headers
  are set: a 403 with `WWW-Authenticate` left as 401, and any response with `Location`
  other than 201/3xx (a 202, a 409) left as a 302/303 redirect. *Who notices:* clients of
  such responses — they now get the status the handler returned. Unchanged in 1.x: a 200
  with a `Location` still goes out as a redirect; use `Response::redirect()`, 2.0 will
  send what the response says.
- **Fields that exist once per message replace what the host already set** — `Content-Type`,
  `Location`, `Content-Length`, `ETag`, the `Cross-Origin-*` policies and the like were sent
  twice. Everything else (`Vary`, `Cache-Control`, `Set-Cookie`, `X-Frame-Options`, unknown
  fields, ...) is added as before. *Who notices:* applications that call `header()` for
  one of these fields before `run()` and also set it on the response — one line goes out
  now, the response's.
- **`run()` does not read the body for a HEAD request.** *Who notices:* nobody on the wire,
  PHP never sent that output; a body stream whose reading has side effects is no longer
  touched for HEAD.
- Cache file format: `serialize()` instead of `var_export()`. An old file is ignored and
  replaced on the first request — which therefore needs the routes file: a deployment that
  ships only a pre-built cache has to rebuild it with this version, and nodes running an
  earlier version must not share a cache file with it (each would rewrite the other's on
  every request). Objects in routes have to survive `serialize()`; `__set_state()` is no longer
  used and their constructor does not run on a cache hit. Routes with an object whose
  serialized state contains a `PDO` or an open file are not cached, and the `error` hook
  says so on every request — before, such a cache was silently rebuilt each time.
- README no longer recommends the cache for production. Measured with OPcache on, a request
  is slower with it than without (the signature has to be verified over the whole file each
  time); the numbers that claimed otherwise are replaced by measured ones.

## [1.1.0] - 2026-08-13

### Added
- `Response::file()` — streamed file responses that never hold the file in memory.
  Supports inline disposition, a single HTTP `Range` (206, with automatic 416 for
  unsatisfiable ranges) and an optional per-response byte cap.
- `Stream\FileStream` — read-only PSR-7 stream over a file or a byte slice of it.

### Changed
- `Router::emit()` writes the body in 8 KB pieces instead of one `echo`. Byte output is
  unchanged, but `ob_start($callback, $chunkSize)` callbacks now see several smaller chunks —
  register them with chunk size 0 if they are not chunk-safe.
- `Router::emit()` stops after three consecutive empty reads from a body that never reports
  `eof()`. v1.0.0 would have kept asking forever (pinning the worker); custom stream
  implementations that stall for longer than three reads are now cut short.
- A response whose body was closed or detached before emit now raises `RouterException`
  instead of dying inside `__toString()` — the failure stays loud rather than sending an
  empty 200 under a Content-Length promising more. The check runs before status and headers
  are sent, so an error handler can still turn it into a proper 500.
- `Response::download()` now sanitizes its filename the same way `file()` does: control
  characters (a raw CRLF made PSR-7 reject the header and killed the download with an
  uncaught `InvalidArgumentException`), bidi overrides and path separators are removed, the
  value is capped at 200 bytes (keeping the extension), and non-ASCII names additionally get
  the RFC 5987 `filename*=UTF-8''…` form. Note that the `filename="…"` form now carries ASCII
  only — the original name travels in `filename*`, which clients prefer. A plain ASCII name
  without separators, surrounding whitespace and below 200 bytes is unchanged; everything
  else is normalized (`.`/`..` become `download`).

### Fixed
- **Emitter no longer buffers the whole response body.** `Router::emit()` pulled the body
  through `echo $response->getBody()`, which cast the stream to a single string: a download
  was silently capped by `memory_limit` and one large response could exhaust it (a 32 MB
  file peaked at ~96 MB). The body is now read in 8 KB chunks; emitted bytes are unchanged
  for string bodies.

### Known limitations
- `Response::file()` advertises `Accept-Ranges: bytes` but sends no validator
  (`ETag`/`Last-Modified`) and ignores `If-Range`. A file replaced under the same path while
  a client resumes a download can therefore be reassembled from two versions. Safe for
  content-addressed or immutable storage; conditional GET support is a follow-up.

## [1.0.0] - 2026-03-15

### Added
- **Routing** with GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD methods.
  - Dynamic route parameters with type constraints (`int`, `float`, `bool`, `slug`, `uuid`, `ulid`, `any`, etc.) and auto-casting.
  - Route groups with prefix and middleware support.
  - Named routes for URL generation.
  - Redirect routes (cache-friendly, no Closures).
  - Duplicate route detection.
  - Trailing slash handling (`strict` or `ignore` mode).
- **PSR-15 Middleware** support (global and per-route).
- **Route Caching** with HMAC-SHA256 signature verification (required in production).
  - Atomic writes, OPcache-friendly `var_export()` format.
  - Graceful fallback when Closures are used (caching silently skipped).
- **Response Facade** with standardized JSON format (`{success, data, error}`).
  - Success helpers: `success()`, `created()`, `accepted()`, `noContent()`, `paginated()`.
  - Error helpers: `error()`, `notFound()`, `unauthorized()`, `forbidden()`, `validationError()`, `methodNotAllowed()`, `tooManyRequests()`, `serverError()`.
  - Non-JSON: `html()`, `text()`, `redirect()`, `download()`.
- **Pluggable Responders** via `ResponderInterface`.
  - `JsonResponder` (default) and `RfcResponder` (RFC 7807 Problem Details).
  - Separate content types for success (2xx) and error (4xx/5xx) responses.
- **URL Generator** for named routes with parameter encoding.
- **Event Hooks** (`dispatch`, `notFound`, `methodNotAllowed`, `error`) with safe error handling.
- **Exception Hierarchy**: `RouterException`, `NotFoundException`, `MethodNotAllowedException`, `RouteNotFoundException`, `DuplicateRouteException`, `CacheException`.
- **PSR-11 Container** integration for controller and middleware resolution.
- **Configuration** via constructor array, environment variables, or fluent API.
- **Strict parameter validation**: invalid types return 400 (not 500), controller TypeErrors bubble up as 500.

[Unreleased]: https://github.com/sodaho/php-router/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/sodaho/php-router/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/sodaho/php-router/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/sodaho/php-router/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/sodaho/php-router/releases/tag/v1.0.0
