# Changelog

## [Unreleased]

### Security
- A route parameter never replaces an attribute the request carries already. Each
  parameter was also set as an attribute of its own name, over whatever was there: an auth
  middleware for every request set `user_id` from the token, the route
  `/users/{user_id}/sessions` put the value from the path in its place, and a route
  middleware that compared the two compared the path with itself. Such a request now ends
  in a 500 before the route's middleware and handler run, and the `error` hook gets a
  `RouterException` (`getDebugMessage()`: `{user_id} in /users/{user_id}/sessions`) — also
  where the attribute is `null`, and also for an attribute the request brought into
  `handle()`. `_route_params` and `RouteMatch::$params` stay complete; `match()` is not
  touched. See README, "Accessing Parameters".
- `{path:any}` takes no empty segment: its value never begins with a slash and never holds
  two in a row. `/files//etc/passwd` gave `/files/{path:any}` the value `/etc/passwd` — an
  absolute path for every helper that takes one as such (`Path::makeAbsolute()` of
  symfony/filesystem left the folder with it), past the dot-segment rule of 2.1.1. Such a
  path no longer matches the route (404 where no other route takes it), and `url()` refuses
  such a value (`a//b`, `/a`) as one that does not lead back. A slash at the end of the
  value stays where the path ends with it (`/files/docs/` gives `docs/`), an empty value
  as well. The built-in pattern is `(?:[^/]+(?:/[^/]+)*(?:/(?=\z))?)?` instead of `.*`.

- A redirect is not sent where a value would be — or make, with the text around it — a `.`
  or `..` segment of the path: `redirect('/docs/{x}-z', '/docs/{x}/')` with `/docs/..-z`
  rendered `/docs/../`, which a client resolves to `/`; `'/a/%2e{x}'` with `.` rendered
  `/a/%2e.`. `handle()` answers 500 and the `error` hook gets a `RouterException`, as for
  a rendering that would change scheme or host. A `..` the target writes itself
  (`'../{x}'`) and dots that make no segment of their own (`'/dl/{x}.json'` with `..`) go
  out as before.
- `RedirectHandler` refuses in its constructor what `RouteCollector::redirect()` refused for
  it: a target with a control character other than a tab, a placeholder that is not
  `{name}` or one where scheme or host belong (`'{a}:{b}'`, `'https:{path}'`,
  `'//{host}/x'`), and a status that is no 3xx status. A handler built by hand — a handler
  of a route of your own — got none of these checks; `'{a}:{b}'` was caught only as the
  redirect went out.

- A route handler `[$object, 'method']` is called on that object, as the callable it is.
  It was taken for `[class name, method]` and ended in a `TypeError` (a 500) with and
  without a container.
- `run()` and `emit()` refuse a protocol version that is no version — a digit, and a dot
  and a digit for a minor one (`1.1`, `1.0`, `2`) — before anything is sent, as they refuse
  a reason phrase with a control character: `withProtocolVersion("1.1\r\nX-Injected: 1")`
  made PHP drop the status line and send its own 200, a 403 went out as a 200. `run()`
  answers 500 and the `error` hook gets the `RouterException`, `emit()` throws it.

- A web app folder (`Router::app()`) opens a file before it reads anything of it, and
  checks the open file: its path still resolves to itself inside the folder, and the file
  under that path is the one that was opened (device and file number). A writer of the
  folder who put a link to a file outside in place of a file between the check (realpath)
  and the open had that file sent — a race that a test with a second process swapping the
  file wins about 150 times in 3000 requests against 2.1.1, and never against this. ETag,
  length and body come from the one open file. What PHP cannot rule out (a directory on the
  way swapped for a link and back between two checks) is in the README; the folder stays
  trusted, writable for the deployment only.
- `Response::file()` takes `Content-Length` and the range from the file it opened
  (`fstat()`), not from `filesize()` before it opens it: a file replaced in between went out
  under the length of the other one — a corrupt download.

### Changed
- With `implicitHead` (the default) a static GET route wins over a dynamic HEAD route, as
  a static route wins over a dynamic one for every method: `HEAD /users/me` is answered by
  `get('/users/me')` like the GET, no longer by `head('/users/{id}')` with `id` = `me`.
  A HEAD route still answers where no static GET route takes its path; without
  `implicitHead` nothing changes. New: `Dispatcher::staticRoute()`.
- Two routes with the same name are refused with a `DuplicateRouteException` when the route
  table is built (and by `new UrlGenerator()` for a list of `Route` objects): the first
  request is answered with 500 and reported, `url()` and `match()` throw. `url()` gave the
  address of whichever route had the name last — `oauth.callback` could lead to a route
  defined further down. A route renamed with a second `name()` is still one route.
- README: two placeholders that take slashes in one route (`/{a:any}/{b:any}`) make the
  work grow with the square of the path's segments — a path of some 800 segments reaches
  PCRE's default backtrack limit and is answered with 500 (reported, never "no match"); a
  test pins it.
- URL encoding can no longer be turned off. `'urlEncoding' => false` (or `0`, `'off'`,
  `''`), `ROUTER_URL_ENCODING=false` (or empty) and `UrlGenerator::setEncodeParams(false)`
  throw a `RouterException` where they are given, instead of switching `url()` to writing
  values as they are: off took every check of `url()` along — a backslash, a control
  character, a `.` or `..` segment, an address that begins with `//` (another host for a
  client) went out unchecked. The key, the variable and `setEncodeParams(true)` are still
  accepted with a value that means on; `setEncodeParams()` is deprecated.
- `addPattern()` refuses a fragment with a named group (`(?P<year>…)`, `(?<year>…)`,
  `(?'year'…)`) or a `(*…)` construct (`(*ACCEPT)`, `(*SKIP)`, `(*COMMIT)`, `(*UTF)`). A
  named group was a parameter of every route that used the pattern — handed to the handler
  as an argument nobody declared (a 500 for a handler that did not take it), and
  `(?P<_route_params>…)` replaced the route's own parameter list; a verb ended or steered
  the match of the whole route (`'a(*ACCEPT)'` matched `/files/abcd` with the value `a`).
  Lookbehinds, groups that do not capture and groups without a name stay allowed — what
  those capture never reaches the parameters.
- A route is frozen once the route table is built from it (`RouteCollector::getData()`, so
  the first request, `match()` or `url()`): `attribute()`, `middleware()`, `name()` and
  assigning `$route->attributes`, `$route->middleware` or `$route->name` throw a
  `RouterException`. The same `Route` object serves every request afterwards; in a worker
  process a value one request wrote onto it (`$request->getAttribute(Route::class)
  ->attribute('role', …)`) was what the next request read. The three properties stay public
  and readable. Writing into one of the arrays in place (`$route->attributes['k'] = …`,
  `$route->middleware[] = …`) is an `Error` of PHP now, also before the table is built —
  the properties have a `set` hook; use the setters or assign the array as a whole.
- `Route::$middleware` (and the constructor parameter) is documented as
  `array<string|object>` since 2.1.1 (it said `array<int, string|object>` before, and 2.1.1
  did not mention it): string keys are kept as the application gave them. Code that hands
  it on as a `list` under PHPStan sees the wider type.
- Middleware named by class: when the container has the name but returns something that
  is no `MiddlewareInterface` (a factory closure registered in place of the instance), the
  request ends in a 500 and the `error` hook gets a `RouterException`. The router used to
  build the class itself with its constructor's defaults instead — a rate limit configured
  with 5 ran with its default.
- A middleware key given a second time is refused with a `RouterException` where it is
  written: a route's `->middleware(['auth' => …])` inside a group with an `'auth'`, an
  inner `middlewareGroup()` with a key of an outer one, a second `Router::middleware()` call
  with a key of the first. Up to 2.1.1 the second one took the place of the first, in its
  place — `RequireAdmin` under the route's `'auth'` replaced the group's `RequireLogin`,
  and a check the route relied on no longer ran. Numbered entries and keys of their own
  add up as before.

### Fixed
- README: a checklist for an authentication server (an `error` hook, rewriting middleware
  before a guard, identities under class-name keys, absolute links from `absoluteUrl()`
  because `run()` takes scheme and host from the client, CORS flags at 405, side effects of
  GET handlers under HEAD, `{path:any}` and files, the length of a request line, route names
  in debug messages); at `METHOD_NOT_ALLOWED` the route's attributes are those of another
  method; `url()` leaves out parameters that are no placeholders without a word.
- `RouteMatch::$path` names the dot segment among the paths that come as they were; the
  private helpers of `Router` that build the table, require the routes file and set up the
  URL generator, `RouteDispatcher::lookup()` and `resolveMiddleware()` say why they do what
  they do.

## [2.1.1] - 2026-10-09

### Security
- A request path with a `.` or `..` segment has no route: `/files/..`, `/files/a/./b` and
  the encoded forms (`/files/%2E%2E/etc/passwd`, `/files/.%2e`) are answered with 404
  before the route table is asked, like a path with `%2F` or a control character; the
  `notFound` hook gets the path as it came. A client resolves such segments before it
  asks, but a request written by hand reached a placeholder with them — `{name}` took the
  value `..`, `{path:any}` the value `../../etc/passwd`. Dots that are not a segment of
  their own (`/files/...`, `/files/.env`) still reach the route. See README, "Slashes in a
  Parameter".
- `run()` and `emit()` refuse a reason phrase with a control character other than a tab
  (`withStatus(403, "Forbidden\r\nX-Injected: 1")`) before anything is sent: `run()`
  answers 500 and the `error` hook gets the `RouterException`, `emit()` throws it. PHP
  dropped such a status line and sent its own 200 — a 403 went out as a 200, with PHP's
  warning in the body and no report. Text beyond ASCII (`202 Akzeptiert ä`) and a tab go
  out as before (RFC 9112).
- `redirect()` refuses a target with a placeholder where scheme or host belong, where the
  route is written: a target that has a scheme or begins with `//` has to write scheme and
  a host before its first placeholder, the host closed by `/`, `?` or `#`. Refused are
  `'https:{path}'`, `'http:/{path}'`, `'https:///{path}'`, `'///{path}'`, `'//{host}/x'`,
  `'{scheme}:{path}'`, `'https://app.example{path}'`, also `'mailto:{to}'` — read as a
  browser reads an address (a backslash is a slash, tabs and blanks at the edges are
  dropped; a scheme begins with a letter, so `'1:relative/{path}'` is a path). An empty
  host in front of a fixed one is not accepted either (`'https:///fixed.example/{path}'`).
  Such a target left scheme or host to the request: `'https:///{path}'` with the value
  `evil.example` sent the client to https://evil.example. Targets without scheme and host
  (`'/new/{path}'`, `'docs/{path}'`, `'?next={path}'`) and those that write both
  (`'https://app.example/{path}'`) are registered as before.
- A redirect whose values would change scheme or host of its target is not sent: an empty
  value in front of a slash — `redirect('/go/{a:bool}/{b}', '/{a}/{b}')` with
  `/go/false/evil.example` rendered `//evil.example`, another host. Checked as the address
  goes out (as a browser reads it): `handle()` answers 500 and the `error` hook gets a
  `RouterException`, instead of sending the client elsewhere. The check compares the
  target as written with the rendered address, not with the host the request came to: an
  empty value in front of a slash ends in a 500 also where the rendering would name the
  application's own host.
- The line written for a failing hook without a `hookError` callback names the event, the
  class of the exception and the file and line it was thrown at — no longer its message.
  A message may carry what a request sent, and a line break in it forged a second line in
  the log. The whole exception still goes to `hookError`.

### Changed
- `Response::paginated()` throws an `InvalidArgumentException` for a page below 1, a total
  below 0 and a page whose last item would be beyond the largest integer, as it did for a
  `perPage` below 1. It answered with nonsense: page 0 gave `from: -4`, a negative page
  negative positions, `PHP_INT_MAX` a float. **An application that passes `?page=0` on
  unchecked gets a 500 instead of that 200 now** — check the page number first.
- Requires `nyholm/psr7` 1.8.2 and `psr/http-factory` 1.1 at least, so that the router runs
  without deprecations under PHP 8.5: the earlier releases that `^1.8` and `^1.0` allowed
  declare parameters PHP 8.5 reports as deprecated when it loads them (implicitly
  nullable). An application that pins one of those has to update it. Tested now: CI runs
  the tests against the lowest versions `composer.json` allows, and validates
  `composer.json` against the committed `composer.lock`.

### Fixed
- A route expression that PCRE gives up on — the backtrack limit or the JIT stack, reached
  by a pattern of your own with nested quantifiers — is a failure, not "no match":
  `handle()` answers 500 and the `error` hook gets a `RouterException` that names the PCRE
  error, `match()` throws it. The request used to go on to the next route that matched (a
  catch-all) or 404 without a word, and the 405 list left the method out; `url()` said the
  values do not fit where it could not tell — it names the PCRE error now.
- A pattern of your own (`addPattern()`) that closes its group early (`'a)|(.*'`) is
  refused when the route table is built. Wrapped in the group of its placeholder it
  compiled, and turned the rest of the route's expression into an alternative that matched
  any path — the route answered for every dynamic route registered after it. Each fragment
  a route uses is now compiled on its own as well, behind an empty group for each
  placeholder of the route, so that it may still refer to them (`(?P=a)`).
- `match()` refuses a method list that no request could use: an empty one (the route was
  never found) and a method that is no token of RFC 9110 (`'GE T'`, `"GET\r\n"` — that one
  stood in the `Allow` header of every 405 for the path and made each of them a 500). A
  route refused as a duplicate for one of its methods no longer keeps the methods before
  it: after a caught `DuplicateRouteException` for `match(['GET', 'POST'], '/x')` (POST
  taken), `get('/x')` works. The same method twice in one list is still a duplicate.
- `redirect()` refuses what never made a redirect where the route is written: a target with
  a control character other than a tab (`"/new\r\nX-Evil: 1"` was registered, and every
  request to the route ended in a 500 — the response refused the `Location` header), and a
  status that is no 3xx status (a `Location` with a 200 is no redirect). This keeps the
  promise of 2.0.0 for redirect routes; `Response::redirect()` takes any status, as before.
- `baseUrl` (config, `setBaseUrl()`, `APP_URL` through `fromEnv()`) refuses a value with a
  control character or a blank. It is put in front of every absolute address as it is: a
  line break made each of them a `Location` header that the response refuses (a 500), a
  blank an address that is none. The message names `APP_URL` where the value came from it.
- `{id:int}` with the value `-0` is answered with "expected integer", not "integer overflow"
  (in debug mode); the status stays 400.
- A request whose URI has no path at all (`new ServerRequest('GET', 'http://example.com')`,
  only built in code — over HTTP a path is never empty) is looked up as `/` instead of
  ending in 404.
- An error response in the format of `RfcResponder` carries `status`, the status code of
  the response, as RFC 9457 has it and the README showed — it was missing. Added behind
  `title` where the details do not name a status; one they name stays as it is.
- `FileStream::read()` with a negative length throws a `RuntimeException`, as PSR-7
  promises and as `TextStream` does — not an `InvalidArgumentException`.
- README: the example of a responder of your own no longer promises XML — a responder
  shapes an array that is always sent as JSON. RFC 7807 is called by its successor, RFC
  9457, throughout.
- README: a static route wins over a dynamic one whatever the order of definition (it
  said "routes match in definition order"); the order of middleware in nested groups;
  that `url()` leaves out parameters that are no placeholders (no query string); that in
  the mode `strict` a group's own address (`/api`) cannot be registered inside it; the
  Quick Start controller has the methods its routes name; hard links and SVG files in a
  web app folder.

### Known limitations
- A redirect target with a placeholder encodes the value as a whole: its slashes become
  `%2F`, so that no value can change scheme or host of an accepted target by what it
  contains (a rendering that would change them anyway, an empty value in front of a
  slash, is a 500). `redirect('/old/{path:any}', '/new/{path}')` therefore sends
  `/old/docs/intro` to `/new/docs%2Fintro`, which this router answers with 404 — for
  redirects that keep the segments of a path, use a route or a handler of your own. See
  README, "Redirect Routes".

## [2.1.0] - 2026-10-06

### Added
- `middlewareGroup()` takes attributes as a third argument: `middlewareGroup($middleware,
  $callback, ['format' => 'envelope'])` is the same as an `attributeGroup()` around the
  `middlewareGroup()`, with the same rules (nested groups add up, the inner group wins per
  key, `Route::attribute()` wins over every group). A third argument that PHP used to drop
  counts now: an array as attributes, anything else is a `TypeError`. See README,
  "Middleware".

## [2.0.1] - 2026-10-06

### Fixed
- `handle()` and `run()` keep their word when PHP cannot open a stream any more (the
  `php://` wrapper unregistered). Not a single response can be built then, not even the
  router's plain-text 500, and `handle()` and `run()` threw. That answer now goes out with a
  body that needs no stream (a string held in memory, a new one for each answer, read-only);
  the `error` hook hears what failed. The empty 500 that HEAD gets when a response refuses
  another body is such a read-only body as well.

## [2.0.0] - 2026-10-03

Every change that breaks something from 1.x is in the table; what is not listed works as
in 1.2.

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
- `status` in the data of the `error` hook (as the last key): the status the router answers
  with for what it reports — 400 for a request it cannot use (a parameter that cannot be
  cast, a request the PSR-7 objects refuse in `run()`), 500 for everything else it answers
  itself; for what already went out (sending fails in `run()`, type `emit`) the status PHP
  has set. A hook that logs can tell a client's mistake from a failure. See README, "Hooks".
- `Contract\RouterInterface` (`handle()`, `match()`, `url()`, `absoluteUrl()`), implemented
  by `Router`: type against it to wrap the router, which is final now. See README,
  "Wrapping the Router".
- `Router::setBaseUrl()`: the base URL for `absoluteUrl()`, for an application that knows it
  only after the router was built. Before the route table is built (the first request,
  `match()`, `url()`, `absoluteUrl()`), like `setBasePath()`; empty means none, as for the
  config key.

### Changed
- **Loud instead of silently wrong.** What is wrong with a route, a pattern, a redirect or
  the configuration is said where it is written (or when the route table is built), not
  found out by a request; `handle()` never throws; exception messages name what is wrong
  and keep the value in `getDebugMessage()`. Each case is a row in the table below.
- Checking every route at every request costs: measured against beta.4, registering a
  route with placeholders takes about 3 µs longer, a static one about 0.4 µs; the table is
  built in about the same time (120 routes: 0.7 ms instead of 0.5 ms for registering and
  building together, under PHP 8.5.7).
- **All classes are `final`, `Router` included** (1.2 announced it); the exceptions stay
  open. See README, "Wrapping the Router".

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
| `->enableCache($file, $key)`, `'cacheFile'`, `'cacheSignature'`, `ROUTER_CACHE_FILE`, `ROUTER_CACHE_KEY` | Remove them: there is no route cache (measured, it made requests slower). `enableCache()` no longer exists; the config keys — also `'cacheFile' => ''`, the 1.x way to keep the cache off — are refused like every key the router does not know (see below), the variables have no effect any more. Delete old cache files |
| `error` hook with `type: 'cache'`, `CacheException`, `Cache\RouteCache` | Gone with the cache |
| `Route::__set_state()`, `RedirectHandler::__set_state()`, `RouteCollector::getNamedRoutesData()` | Removed. Build routes from the routes file instead of `var_export()`ing them; named routes: `getRoutes()` |
| A config key `'emitChunkSize'` is ignored like every key the router does not know | It is an option now: an integer, or a string of digits, from 1024 to 16777216; anything else throws `RouterException` |
| `'baseUrl'` of another type than string, `false` or `0` (an array, `5`) ends in a `TypeError` at the first `url()` or `absoluteUrl()` (`[]` and `0.0` meant none) | `RouterException` when the router is built. Empty values mean none as before (`null`, `''`, `false`, `0`, `'0'`) |
| `url()` with a float that is not finite (`NAN`, `INF`) writes `NAN` or `INF` (PHP 8.5 warns for `NAN`) | `RouterException` naming the parameter |
| A config key the router does not know is ignored: a typo (`basepath`), a key of 1.x (`cacheFile`), a list instead of a map | `RouterException` from `create()`, `new Router()` and `fromEnv()`; the message names the known keys, `getDebugMessage()` the unknown ones |
| `Router`, `RouteCollector`, `RouteDispatcher`, `Route`, `Dispatcher`, `MiddlewareHandler`, `RouteHandler` and `RedirectHandler` can be extended | They are `final`. What a subclass of `Router` did has a place of its own: a middleware for every request (also a 404 page), `setErrorHandler()`, hooks, route attributes, `Router::match()`, `setBaseUrl()`; to wrap the router, type against `Contract\RouterInterface`, and double that interface in tests (`createMock(Router::class)` fails: the class is final). `run()` no longer guards against a `handle()` that throws (only a subclass could) |
| A request path with `%2F`, `%5C` or a backslash is decoded and matched: `/files/a%2Fb` reaches `/files/{path:any}` as `a/b` and `/a%2Fb` the route `/a/b` | 404, the route table is not asked (what Apache does by default); the `notFound` hook and `RouteMatch::$path` carry the path as requested. `%252F` — a literal `%2F` in a value — works as before |
| A request path with a control character — encoded (`%00` to `%1F`, `%7F`) or, from a request object of another make, as it stands — reaches the handler through a placeholder — `/users/a%0A` hands over `"a\n"`, also `{path:any}` (except for `%0A` and `%0D`) and `/avatars/{name}.webp` | 404 like `%2F`, before the route table and before an app folder (`Router::app()`, new in 2.0: no file and no start page); `RouteMatch::$path` and the `notFound` hook carry the path as requested. Typed placeholders and literal routes answered 404 before as well |
| `url()` writes a value with a control character encoded (`%0A`) | It throws `RouterException` with URL encoding on: that address has no route |
| `url()` writes a slash in a parameter value as `%2F` | The router refuses `%2F`, so `url()` keeps the slash where the placeholder takes it (`{path:any}`, an own pattern), each segment encoded on its own: `/files/a/b%20c`. Values with a backslash throw, and so does an address that would contain a `.` or `..` segment (`/files/../x`; `/dl/{name}.json` with `..` is fine) or whose path below the base path would begin with `//` (`/{path:any}` with `/evil.example/x`). With `'urlEncoding' => false` values go in as given, as before |
| `url()` returns an address for any value — also one that can never reach the route: `12a` or `01` for `{id:int}` (404 or 400), an empty value, a slash where one segment is expected | With URL encoding on (the default) the address has to lead back to its route with exactly these values: the path, values in place, is matched against the route's pattern (in the trailing slash mode `ignore` without the slashes at its end), and each value has to pass the cast of its type. What does not fit throws `RouterException` (the message names the route, the address is in `getDebugMessage()`). Not checked: whether another route, registered before this one, takes the same path (`/users/me` in front of `/users/{id}` with `id => 'me'`) |
| `url()` with a parameter that is `null` writes an empty value | It throws `RouterException` |
| `url()` writes the literal text of route pattern and base path as it stands: `/my app/über uns/a%20b`, `/100%/x`, `/a?b` — and `/\host/x` for a route pattern with a backslash (or `//host/x` for a `UrlGenerator` of your own with `setBasePath('//host')`) | With URL encoding on (the default) literal text is percent-encoded like the values (`/my%20app/%C3%BCber%20uns/a%20b`, `/100%25/x`; `:`, `@` and the sub-delimiters `!$&'()*+,;=` stay as they are, so that `/v1:batch` and `/@{user}` read as before), and the address has to begin with a single `/` and contain no backslash — otherwise `RouterException` |
| A route pattern is literal text, whatever it contains: a regular expression in the placeholder (`{id:\d+}`), an optional segment (`/users[/{id}]`), a brace that is no placeholder (`/literal/{`), a backslash, a percent-encoded character (`/a%2Fb`, `/caf%C3%A9`), a control character, `?`, `#`, a `.`/`..` segment. Some of these worked: `/literal/{` and `/a}b` fully, also through `url()`; `/a%2Fb` for the request `/a%252Fb`, a tab for `%09` — not for the address `url()` wrote, and not as the author most likely meant | `RouterException` when the route is registered. Braces are placeholders (`{name}`, `{name:type}`) and nothing else — deliberately, a lone brace is almost always a mistake —, brackets are refused altogether, and a pattern is a plain path, written decoded: no backslash, control character, percent-encoded character, `?`, `#` or dot segment (write `/a b` for `/a%20b`; `?` and `#` are not part of a path) |
| The same placeholder twice in one pattern (`/a/{id}/b/{id}`), a name that begins with a digit or has more than 32 characters (measured: PCRE 10.36 refuses the 33rd, 10.44 takes it): a PHP warning for every request that reaches the route, and no match. `{_route_params}`: a 500. A name with a letter beyond ASCII (`{näme}`) was a placeholder or literal text depending on the locale (`\w`) | `RouterException` when the route is registered. Names are ASCII letters, digits and `_`, under every locale |
| A pattern type nobody defined (`{id:integer}`) silently means "one segment" | `RouterException` when the route table is built — at the first request, `match()` or `url()`; through `handle()` a 500 for every request, reported to the `error` hook |
| `addPattern()` takes any name and any fragment. One with an unescaped `#`, or one that does not compile, breaks every route that uses it: a warning per request, no match | The name has to consist of ASCII letters, digits and underscores, and an unescaped `#` is refused (write `\#`) — both in `addPattern()`. A route that does not compile with its own patterns throws when the route table is built |
| `redirect('/old/{id}', '/new/{slug}')` sends `Location: /new/{slug}`; `redirect('/old/{id:int}', '/new/{id:int}')` sends `Location: /new/{id:int}` | `RouterException` when the redirect is registered: the target takes `{name}` and nothing else in braces, and every name has to exist in the source |
| `'trailingSlash'` with a value other than `'strict'` or `'ignore'` leaves the router half in one mode and half in the other (`/users/` is a 404 in both spellings) | `RouterException` when the router is created; `null` and `''` mean the default, in the config as in the variable. With `fromEnv()` also for `ROUTER_TRAILING_SLASH` (the message names the variable) |
| A base path is literal text as well: with a backslash, a percent-encoded character (`/my%20app` waits for the request `/my%2520app`), a control character, `?`, `#` or a `.`/`..` segment it is accepted, and `url()` writes an address for all of them — one that does not lead back for most | `RouterException` when it is configured (`'basePath'`, `setBasePath()`, `ROUTER_BASE_PATH`), by the same rule as for a route pattern. An empty segment (`/api//v1`) stays allowed — it worked, and works |
| `setBasePath()`, `setDebug()` and `loadRoutes()` after the first request, `match()` or `url()` are accepted and have no effect, or half of one | They throw `RouterException`: call them before the route table is built |
| The routes file is `require`d again by every `handle()` whose route table could not be built. A file that declares a function or a class then ends the second request in PHP's "Cannot redeclare", which nothing catches | A router loads its routes file once for each `loadRoutes()`: its callable is tried again, what it threw while it was loaded is thrown again — every such request is a 500 through the `error` hook. Another router, or another `loadRoutes()`, requires the file again, as in 1.x: a routes file that declares a function or a class can be used by one router per process (a worker that builds a router per request: keep declarations out of the routes file). Its closure sees the router as `$this`, as in 1.x |
| A response whose body was closed or detached before `run()` could send it makes `run()` throw — PHP's own answer, see above; so does a getter of the response that throws (`getProtocolVersion()`, `getHeaders()`). And a body that fails while it is sent: PHP's stack trace follows the part that went out where `display_errors` is on | Before the first byte (everything `run()` reads from the response is read before it sends): a plain-text 500, reported. While the body is sent: nothing can be answered any more, but it is reported, whatever it threw, and nothing else goes out. Once output has started, the response is not read at all — one report of type `emit`, from `run()` and `emit()` alike. `emit()` throws as before otherwise |
| A placeholder that has the name of the handler's first parameter (`/x/{request}` with `fn ($request) => …`) ends in a 500 with PHP's `Error` "Named parameter $request overwrites previous argument" | Still a 500, but a `RouterException` that names the placeholder (the `Error` is in `getPrevious()`) |
| `new UrlGenerator($routes)` without the patterns of the collector takes a pattern type of your own (`{day:date}`) for "one segment" | It throws `RouterException` with URL encoding on: pass `RouteCollector::getPatterns()` as the second argument, as `Router::url()` does |
| A `clone` of a router whose table was built shares the table and dispatcher with the original: a hook added to the clone fires for the original too | Cloning a router once it was used — a request, `match()` or `url()`, whether its table could be built or not — throws `RouterException`; clone it before. Such a clone is a router of its own. |
| A failing hook with a closed `STDERR`: the `error` hook throws a `TypeError` out of `handle()`, a `dispatch` or `notFound` hook turns the answer into a 500 | The line that cannot be written is dropped; the request goes on (200, 500, 404 as the answer was) |
| `handle()` throws when a responder set with `Response::setResponder()` throws while the router's own 500 is built | `handle()` never throws. The answer is a plain-text 500 without the responder; the `error` hook gets the responder's exception as well. A `try`/`catch` around `handle()` for this case no longer catches anything — build your own last answer in `setErrorHandler()`, which is asked before the plain answer, once for each exception. The same goes for a response object that refuses to lose its body for a HEAD request |
| `run()` lets the exception out when the request cannot be built from what the server hands over (`Host: x:99999999`, a header value with a control character): PHP's own answer — a 500 without a body, or with `display_errors` on a 200 with the stack trace on the page | `400 Bad Request` in the format of the responder (plain text when that fails), reported to the `error` hook as a `RouterException` "The request could not be read" (what the PSR-7 objects said is in `getPrevious()` and `getDebugMessage()`, not in the message or the response). Whatever else fails while the request is built is a 500, reported as it is |
| A request object whose `getMethod()` or `getUri()` throws makes `handle()` throw | A 500 like any other; the `error` hook's `method` and `path` are `''` for what the request object fails to say |
| Messages repeat values: `Cannot read file: /srv/…` (`Response::file()`, `FileStream`), `Parameter 'id': expected integer, got '01'` (in the 400 body in debug mode), `Optional segments [] are not supported in pattern "…"`, `Route GET /users is already registered`, `Response not sent: output had already started at /srv/…/index.php:12` | The message names what is wrong; path, pattern, route and place are in `getDebugMessage()`, the parameter value is in the path the `error` hook gets. `maxChunk must be at least 1` no longer repeats the number |
| `Response::paginated()` computes `last_page` through a float: wrong for totals beyond 2^53, negative near `PHP_INT_MAX` (PHP 8.5 warns) | Counted in whole numbers |
| `RfcResponder` casts every numeric `status` in the details to an integer: `404.7` becomes `404`, `'1e3'` becomes `1000`, a float beyond the integer range some other number (PHP 8.5 warns) | Only a three-digit string becomes an integer; every other value goes out as the application passed it |
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

[Unreleased]: https://github.com/sodaho/php-router/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/sodaho/php-router/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/sodaho/php-router/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/sodaho/php-router/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/sodaho/php-router/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/sodaho/php-router/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/sodaho/php-router/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/sodaho/php-router/releases/tag/v1.0.0
