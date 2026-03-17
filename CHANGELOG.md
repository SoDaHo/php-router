# Changelog

## [Unreleased]

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
