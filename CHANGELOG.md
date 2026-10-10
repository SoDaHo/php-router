# Changelog

## [2.2.0] - 2026-10-10

Changes behaviour that 2.1.1 documented.

### Security
- A route parameter named like an attribute the request carries ends the request in a 500; it replaced the attribute.
- `{path:any}` takes no empty segment: `/files//etc/passwd` is a 404 (it gave `/etc/passwd`), `url()` refuses one.
- `Router::app()` opens a file, then checks the open file; a link put in its place between check and open was sent.
- A redirect whose value makes a `.` or `..` path segment is a 500; it was sent.
- `url()` refuses a `.` or `..` segment in the whole address, base path included; it checked the route's part only.
- `new RedirectHandler()` refuses a control character but tab, a placeholder not `{name}` or in scheme or host.
- `RedirectHandler::handle()` refuses a placeholder without value and parameters that are no array of scalars (500).
- `run()`/`emit()` refuse a protocol version that is no version and a status code outside 100–599.
- `run()`/`emit()` refuse a header name that is no token and a header value with a control character other than tab.
- `run()`/`emit()` refuse `Transfer-Encoding` and a `Content-Length` not one value of at most 18 significant digits.
- `run()`/`emit()` refuse a `Content-Length` on 1xx and 204, one other than `0` on 205.
- `run()`/`emit()` refuse a body of a size other than 0 (or unknown) on 1xx, 204, 205, 304, unread; it was sent.

### Changed
- URL encoding is always on: `urlEncoding` off, `ROUTER_URL_ENCODING` off or empty, `setEncodeParams(false)` throw.
- `addPattern()` refuses a named group, a `(*…)` verb, a callout `(?C…)` and an option that turns on `x` or `xx`.
- A route is frozen when the table is built: setters and assignments throw; writing its arrays in place is an `Error`.
- A middleware key given a second time throws `RouterException`; the second entry replaced the first.
- A container entry for middleware that is no `MiddlewareInterface` is a 500; the class was built with its defaults.
- A container entry for a controller that is no object of the named class is a 500; any object was called.
- Two routes of different patterns under one name throw `DuplicateRouteException` when the table is built.
- With `implicitHead`, a static GET route wins over a dynamic HEAD route.
- Mode `strict`: `get('')` in a group registers the group's own address (`/api`); it registered `/api/`.
- `Response::redirect()`, redirect routes, `RedirectHandler` take 301, 302, 303, 307, 308; they took any (3xx) status.
- `baseUrl`, `APP_URL`, `setBaseUrl()` and `UrlGenerator::setBaseUrl()` take `http(s)://host[:port][/path]` only.
- `UrlGenerator::setBaseUrl('')` means none: `absoluteUrl()` throws; it gave a relative address.
- `RfcResponder` drops `type`, `title` and `status` of the details; `status` is the response's.
- `run()`/`emit()` wait `emitIdleTimeout` for the next byte, then throw; they stopped silently at the third empty read.
- `run()`/`emit()` end a body short of or beyond its `Content-Length` with `RouterException`, sent up to the length.
- `run()`/`emit()` refuse a `Content-Length` above 0 before an empty body; `emit()` needs `withBody: false` for HEAD.
- `run()`/`emit()` ask the body for its size before the first byte, unless `withBody` is false.
- `basePath` and `routesFile` in the config take a string or `null` (`basePath` also `false`); other types throw.
- `Response::tooManyRequests()` throws for a number below 0.
- Four messages (duplicate placeholder, missing parameter, unfitting values, controller) name it in `getDebugMessage()`.
- `Route::$middleware` is typed `array<string|object>`.

### Added
- `emitIdleTimeout` (seconds above 0 to 3600, default 30), `Dispatcher::staticRoute()`, `Response::REDIRECT_STATUSES`.

### Deprecated
- `UrlGenerator::setEncodeParams()`: `true` changes nothing, `false` throws.

### Fixed
- A table that cannot be built takes back the middleware, apps and hooks the routes file registered on the router.
- `Router::app('/', '/')` serves files; it served none.
- A handler `[$object, 'method']` is called on that object; it ended in a `TypeError`.
- `Response::file()` takes length and range from the opened file, not from `filesize()` before opening.

## [2.1.1] - 2026-10-09

### Security
- A request path with a `.` or `..` segment (also encoded) is a 404 before the route table is asked.
- `run()`/`emit()` refuse a reason phrase with a control character other than a tab.
- `redirect()` refuses a target with a placeholder where scheme or host belong.
- A redirect whose values would change scheme or host of its target is a 500.
- The line for a failing hook without `hookError` names event, exception class, file and line, not the message.

### Changed
- `Response::paginated()` throws `InvalidArgumentException` for a page below 1, a total below 0 and an overflow.
- Requires nyholm/psr7 ^1.8.2 and psr/http-factory ^1.1.

### Fixed
- A route expression PCRE gives up on is a 500 (`match()` throws), not "no match".
- `addPattern()` fragments that close their group are refused when the table is built.
- `match()` refuses an empty method list and a method that is no token; a refused route keeps none of its methods.
- `redirect()` refuses a target with a control character other than a tab, and a status that is no 3xx.
- `baseUrl` refuses a control character and a blank.
- `{id:int}` with `-0` gives "expected integer"; a URI without path is looked up as `/`.
- `RfcResponder` errors carry `status`; `FileStream::read()` with a negative length throws `RuntimeException`.

## [2.1.0] - 2026-10-06

### Added
- `middlewareGroup()` takes attributes as a third argument, like an `attributeGroup()` around it.

## [2.0.1] - 2026-10-06

### Fixed
- `handle()` and `run()` answer with a body that needs no stream when PHP cannot open one.

## [2.0.0] - 2026-10-03

### Added
- `Router::app()`, `emitChunkSize`, `status` in the `error` hook, `Contract\RouterInterface`, `Router::setBaseUrl()`.

### Changed
- Wrong routes, patterns, redirects and config values throw where they are written or when the table is built.
- `handle()` does not throw; messages keep values in `getDebugMessage()`.
- Classes other than the exceptions are `final`; `implicitHead` is on.

### Removed
- Route cache (`enableCache()`, `cacheFile`, `ROUTER_CACHE_*`, `CacheException`), `__set_state`, `getNamedRoutesData`.

### Upgrading from 1.x
- Requires PHP ^8.5.
- `create()` and `new Router()` read no environment: use `Router::fromEnv()` or pass the values.
- `null` in the config means the default; unknown keys and values that are not boolean-like throw.
- Remove the cache calls, keys, variables and files.
- Replace a subclass of `Router` with middleware, `setErrorHandler()`, hooks or `Contract\RouterInterface`.
- Paths with `%2F`, `%5C`, a backslash or a control character are a 404.
- Route patterns with a regex, brackets, a stray brace or an encoded character throw.
- `url()` throws for values that do not lead back to their route.
- `'implicitHead' => false` answers HEAD on a GET route with 405.

Older versions: see the git tags.

[2.2.0]: https://github.com/sodaho/php-router/compare/v2.1.1...v2.2.0
[2.1.1]: https://github.com/sodaho/php-router/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/sodaho/php-router/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/sodaho/php-router/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/sodaho/php-router/compare/v1.2.0...v2.0.0
