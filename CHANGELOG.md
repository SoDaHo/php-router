# Changelog

## [Unreleased]

2.0.0, in progress on branch `2.x`. Every change that breaks something from 1.x is in the
table; what is not listed works as in 1.2.

### Added
- **`Router::app()`** serves a folder with a built web app under a prefix — one line per
  app, also at `/`. Routes come first; where none matches, a file of the folder is sent
  (`Content-Type`, `nosniff`, `Range`, HEAD), otherwise the start page, and a path that looks
  like a missing file is a 404. Never served: anything outside the folder, hidden and
  unreadable files, types that are not on the list (PHP sources cannot be put on it, source
  maps are off by default), paths with NUL, backslash, encoded separators, empty segments
  or segments that end in a dot or space; a path with a colon below the prefix is never
  looked up as a file. Cache headers are configurable: everything goes out with `no-cache`
  until the application says which files never change (`'immutable' => AppFolder::HASHED`
  for what a bundler hashed in `assets/` or `static/`). Files carry an `ETag` (a hash of
  the content up to 64 KiB, so that a new start page of the same size is told apart; none
  for larger files where the file system reports no file numbers);
  `If-None-Match` is answered with 304; a `Range` counts for `GET` only, and one with an
  `If-Range` gets the whole file. See README, "Serving a Web App".
- `emitChunkSize` config option: how many bytes `run()`/`emit()` read from the response body
  at a time (default 8192 as before; 1024 to 16777216, anything else is refused).

### Upgrading from 1.x

| 1.x | 2.0 |
|-----|-----|
| PHP `^8.2` | PHP `^8.5` |
| `Router::create()` and `new Router()` read `APP_DEBUG`, `APP_ENV`, `APP_URL`, `ROUTER_BASE_PATH`, `ROUTER_TRAILING_SLASH`, `ROUTER_URL_ENCODING`, `ROUTER_CACHE_FILE` and `ROUTER_CACHE_KEY` for what the config array leaves out | They read nothing. `Router::fromEnv()` reads `APP_DEBUG`, `APP_URL`, `ROUTER_BASE_PATH`, `ROUTER_TRAILING_SLASH` and `ROUTER_URL_ENCODING` — or pass the values yourself |
| `Router::boot($config, $routesFile)` reads the environment as well | It does not: `Router::fromEnv($config)->loadRoutes($routesFile)->run()` |
| Variables are read with `getenv($name)`, which under PHP-FPM and mod_php also returns what the web server sends with the request (`fastcgi_param`, `SetEnv`) | `fromEnv()` reads `$_ENV`, then `getenv($name, true)`: the environment of the process (FPM: the pool's `env[NAME]`). Per-request parameters count only where PHP copies them into `$_ENV` (`variables_order` with `E` — not with `php.ini-production`). Who configures the router with `fastcgi_param` or `SetEnv`: use the pool's `env[NAME]`, a real environment variable, or the config array |
| `APP_ENV=local`, `dev` or `development` switches debug on | `APP_ENV` is not read. Debug: `'debug' => true`, or `APP_DEBUG=true` with `fromEnv()` |
| A config key with the value `null` (`debug`, `basePath`, `baseUrl`, `trailingSlash`, `urlEncoding`) means "ask the environment" | `null` means the default. With `fromEnv()` a key you pass settles it — also with `null`, `false` or `''` — and its variable is not looked at |
| `APP_DEBUG=maybe`, `ROUTER_URL_ENCODING=sometimes` and other values that are not boolean-like count as "off", silently | `fromEnv()` throws a `RouterException` that names the variable. Empty counts as off |
| `'urlEncoding'` accepts only `true`/`false`; anything else (`'false'`, `0`) ends in a `TypeError` at the first `url()` | Boolean-like values work (`'false'`, `'0'`, `0`, `'off'` switch it off). `null` means the default (on), an empty string counts as off; anything else is refused when the router is created |
| `->enableCache($file, $key)`, `'cacheFile'`, `'cacheSignature'`, `ROUTER_CACHE_FILE`, `ROUTER_CACHE_KEY` | Remove them: there is no route cache (measured, it made requests slower). `enableCache()` no longer exists; the config keys — also `'cacheFile' => ''`, the 1.x way to keep the cache off — and the variables have no effect any more (a later beta will refuse config keys the router does not know). Delete old cache files |
| `error` hook with `type: 'cache'`, `CacheException`, `Cache\RouteCache` | Gone with the cache |
| `Route::__set_state()`, `RedirectHandler::__set_state()`, `RouteCollector::getNamedRoutesData()` | Removed. Build routes from the routes file instead of `var_export()`ing them; named routes: `getRoutes()` |
| A config key `'emitChunkSize'` is ignored like every key the router does not know | It is an option now: an integer, or a string of digits, from 1024 to 16777216; anything else throws `RouterException` |
| A subclass may have its own `Router::app()`, `RouteDispatcher::setApps()` or `RouteCollector::getPatterns()` | They are methods of the library now; a member of the same name in a subclass has to be compatible |
| A request path with `%2F`, `%5C` or a backslash is decoded and matched: `/files/a%2Fb` reaches `/files/{path:any}` as `a/b` and `/a%2Fb` the route `/a/b` | 404, the route table is not asked (what Apache does by default); the `notFound` hook and `RouteMatch::$path` carry the path as requested. `%252F` — a literal `%2F` in a value — works as before |
| `url()` writes a slash in a parameter value as `%2F` | Where the placeholder takes slashes (`{path:any}`, an own pattern) the slash stays and each segment is encoded: `/files/a/b%20c`. For every other placeholder a value with a slash throws `RouterException` — the address never reached its route. Values with a backslash throw as well, and so does an address that would contain a `.` or `..` segment (`/files/../x`; `/dl/{name}.json` with `..` is fine) or whose path below the base path would begin with `//` (`/{path:any}` with `/evil.example/x`). With `'urlEncoding' => false` values go in as given, as before |
| An own pattern (`addPattern()`) that looks at the text around it (`a/b(?=/tail)`) or refers to another placeholder: `url()` wrote the slash of a value as `%2F`, and the address reached the route | `url()` asks the fragment on its own whether a value with a slash fits, so for such a pattern it throws. A pattern has to stand on its own (until a later 2.0 beta checks the finished address against the whole route) |
| `url()` returns whatever route pattern and base path make of the address — also `/\host/x` for a route pattern with a backslash, or `//host/x` for a base path `//host` | With URL encoding on (the default) it throws unless the address begins with a single `/` and contains no backslash or control character |
| `run()`/`emit()` send a response with status 200 and a `Location` header as 302 (PHP rewrites the status) | It goes out as 200, as the response says. A redirect needs a 3xx status: `Response::redirect()` |
| HEAD on a route registered with `get()`: 405, unless `'implicitHead' => true` | `implicitHead` is on by default — see below. `'implicitHead' => false` (on a `RouteDispatcher` of your own: `setImplicitHead(false)`) brings 1.x back |

What HEAD by default means for an application that changes nothing:

- **Handler and route middleware of a GET route run for HEAD requests** — they see the method
  `HEAD`. A one-time link behind `GET /confirm/{token}` is used up by a link scanner that
  asks with HEAD; a check like `$request->getMethod() === 'GET'` does not match. In 1.x such
  a request ended in 405 before the route's middleware or handler ran.
- Hooks: `dispatch` (or `error`) fires where `methodNotAllowed` did.
- Every response to a HEAD request leaves `handle()` without a body: that of a GET route, of
  a `head()` route, a 404, a 405, an error. Headers stay as they are. (Over `run()` nothing
  changes on the wire but status and headers — PHP never sent a body for HEAD.)
- `Allow`, the `allowed` list in the 405 body, the hook's `allowed_methods` and
  `RouteMatch::allowedMethods()` name HEAD directly behind GET — for every method, not only
  for HEAD requests: a POST to a GET-only path now says `GET, HEAD`. Where HEAD is registered
  itself (`any()`), it moves there: `GET, POST, ..., HEAD` becomes `GET, HEAD, POST, ...`.

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
