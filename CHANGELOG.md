# Changelog

## [Unreleased]

### Added
- `Response::file()` — streamed file responses that never hold the file in memory.
  Supports inline disposition, a single HTTP `Range` (206, with automatic 416 for
  unsatisfiable ranges) and an optional per-response byte cap.
- `Stream\FileStream` — read-only PSR-7 stream over a file or a byte slice of it.

### Changed
- `Router::emit()` writes the body in 8 KB pieces instead of one `echo`. Byte output is
  unchanged, but `ob_start($callback, $chunkSize)` callbacks now see several smaller chunks —
  register them with chunk size 0 if they are not chunk-safe.
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

[Unreleased]: https://github.com/sodaho/php-router/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sodaho/php-router/releases/tag/v1.0.0
