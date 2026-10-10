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
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return Response::success([['id' => 1, 'name' => 'John']]);
    }

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $user = ['id' => $id, 'name' => 'John'];
        return Response::success($user);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $user = ['id' => 2] + (array) $request->getParsedBody();
        return Response::created($user, location: '/users/2');
    }
}
```

Every method a route names has to exist: a missing one is a 500, and without an `error`
hook nothing tells you why (see [Hooks](#hooks-logging)).

A handler is `[ControllerClass::class, 'method']` (built through the container where it
has the class, otherwise with `new`), any callable — a closure, `[$controller, 'method']`
on an object you built, an invokable object — or a PSR-15 `RequestHandlerInterface`.

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

// The seven common methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD
$r->any('/webhook', $handler);
```

`match()` takes at least one method, each a token of RFC 9110 — ASCII letters, digits and
``!#$%&'*+-.^_`|~`` (`PROPFIND`, `M-SEARCH`); it upper-cases them. An empty list or a
method with a blank, a line break or a comma is refused where it is written: no request
could ever ask for it. A route that is refused takes none of its methods (a later route
may still use them).

A `HEAD` request to a route registered with `get()` is answered by that route: its
middleware and handler run, and the response goes out with its status and headers but
without its body. `Allow` names `HEAD` right behind `GET`. The router does not change the
method — middleware and handler see `HEAD` (unless a middleware of yours rewrites it) and
are free to answer differently than for GET. Keep GET handlers free of side effects, or
give them a `head()` route of their own: link scanners and mail clients ask with HEAD. For a
static GET route that `head()` route has to be static as well — a dynamic one
(`head('/{path:any}')`) no longer shields it: a static route wins over a dynamic one for
HEAD as for GET, so the GET handler runs. Or turn `implicitHead` off. No
response to a HEAD request leaves `handle()` with a body, whoever wrote it; that includes
a request a middleware turned into HEAD, or out of it, on its way in.

A HEAD route of its own (`$r->head()`) answers HEAD for its path — except where a static
GET route takes the path and the HEAD route is a dynamic one: a static route wins over a
dynamic one for every method, so `HEAD /users/me` is answered like `GET /users/me` by the
static GET route, not by `head('/users/{id}')` with `id` = `me`.

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
| `any` | `(?:[^/]+(?:/[^/]+)*(?:/(?=\z))?)?` | `{path:any}` → anything/here — no empty segment (see below) |

### Slashes in a Parameter

A parameter is one path segment unless its pattern says otherwise (`any`, or a pattern of
your own that takes a slash). **A separator hidden in the path has no route:** a request
whose path contains `%2F`, `%5C` or a backslash is answered with 404 — the route table is
not asked, the `notFound` hook gets the path as it came. Decoded, `/files/a%2Fb` would be
two segments for the router and one for a proxy or the access rules of the web server in
front of it; Apache refuses such paths by default for the same reason. A percent sign that
is meant literally still works: `/tags/a%252Fb` reaches the handler as `a%2Fb`. A
middleware that decodes the path itself before it passes the request on opens the door
again — the rule looks at the path of the request it is given.

**A control character has no route either:** a path with `%00` to `%1F` or `%7F` (a line
break, a tab, a NUL byte) is answered with 404 in the same way, before the route table and
before an app folder. No route pattern and no base path may contain one, so only a
placeholder could take it — and hand a line break in an id to the handler. `url()`
refuses a value with a control character for the same reason.

**Nor has a `.` or `..` segment:** `/files/..`, `/files/a/./b` and the encoded forms
(`/files/%2E%2E/etc/passwd`, `/files/.%2e`) are answered with 404 in the same way. A
client resolves such segments before it asks, so what arrives with one was written by
hand — and a placeholder would take it: `{name}` the value `..`, `{path:any}` a value
that climbs out of its folder (`../../etc/passwd`). Dots that are not a segment of their
own stay a name like any other: `/files/...`, `/files/..a`, `/files/.env`.

**`{path:any}` takes no empty segment:** its value never begins with a slash and never
holds two in a row, so `/files//etc/passwd` and `/files/a//b` do not match
`/files/{path:any}` (404 where no other route takes them). Up to 2.1.1 the first gave the
value `/etc/passwd` — an absolute path for every helper that takes one as such
(`Path::makeAbsolute('/etc/passwd', '/srv/files')` is `/etc/passwd`). One slash at the end
of the value stays where the path ends with it (`/files/docs/` gives `docs/` in the mode
`strict`), and the value may be empty (`/files/`). Still: build a file path from a value
only after `realpath()` and a prefix check — on Windows `C:/…` is absolute as well.

### Custom Patterns

```php
$r->addPattern('date', '\d{4}-\d{2}-\d{2}');
$r->get('/events/{date:date}', $handler);  // 2024-12-06
```

The name is made of ASCII letters, digits and underscores. The fragment becomes part of a
regular expression delimited by `#`: write a literal `#` as `\#`. A pattern may be added
after the routes that use it. The fragment is a regular expression of its own — its
parentheses pair up (`'a)|(.*'` is refused when the route table is built); it may refer to
the placeholders of its route by name (`(?P=other)`), but names nothing itself: a named
group (`(?P<year>…)`, `(?<year>…)`, `(?'year'…)`) would be a parameter of every route that
uses the pattern, and a `(*…)` construct (`(*ACCEPT)`, `(*SKIP)`, `(*COMMIT)`) ends or
steers the match of the whole route — `addPattern()` refuses both. Lookbehinds
(`(?<=…)`, `(?<!…)`), groups that do not capture and groups without a name stay allowed;
what a group without a name captures never becomes a parameter. Refer to other
placeholders by name, not by number: `\1` counts the groups of the whole route. Keep it free of nested quantifiers
(`(a+)+`): where PCRE gives up on an expression (the backtrack limit, the JIT stack), the
request is answered with 500 and the `error` hook gets a `RouterException` naming the PCRE
error — never treated as "no match", which would hand it to the next route.

### What a Route Pattern May Contain

A route that is wrong as it is written is refused where it is written, with a
`RouterException` — not found out by the first request:

```php
$r->get('/users/{id:\d+}', $handler);        // a regular expression goes into addPattern()
$r->get('/users[/{id}]', $handler);          // no optional segments: define two routes
$r->get('/a/{id}/b/{id}', $handler);         // the same placeholder twice
$r->get('/caf%C3%A9', $handler);             // a pattern is written decoded: '/café'
$r->get('/search?q={q}', $handler);          // a pattern is a path: no '?' or '#'
$r->addPattern('hex', '[0-9a-f#]+');         // unescaped '#'
$r->redirect('/old/{id}', '/new/{slug}');    // the target uses a placeholder its source does not have
$r->redirect('/go/{to}', 'https:{to}');      // a placeholder where scheme or host belong
```

A route pattern is compared with the request path after it was decoded, so it is written
decoded (`/a b`, `/über`, `/100%`) — a percent-encoded character in a pattern (`%20`) is
refused, and so are a backslash, a control character, `?`, `#` and a `.`/`..` segment: no
request a client sends looks like that. (The same rule holds for `basePath`.) Curly braces
are placeholders and nothing else — `{`, `}` cannot be literal text. A placeholder is
`{name}` or `{name:type}`; the name begins with an ASCII letter or an underscore, has at most 32
characters (more is refused by older PCRE versions), and is not `_route_params`. Square
brackets cannot be part of a route pattern at all. Two things show only when the
route table is built (at the first request, `match()` or `url()`), because patterns may
still be added until then: a type nobody defined (`{id:integer}`) and a pattern of your own
with which the route does not compile. Through `handle()` that is a 500 for every request,
reported to the `error` hook — the application does not come up half-working.

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
    $id === $request->getAttribute('id');                  // same value
    $request->getAttribute('_route_params');               // ['id' => 5]: all of them, cast
}
```

The first parameter of a handler receives the request, whatever it is called. A
placeholder must not have that name (`/x/{request}` with `fn ($request) => ...`): the
request ends in a 500, and the `RouterException` behind it names the placeholder.

**A parameter never replaces an attribute the request carries already.** An auth
middleware for every request sets `user_id` from the token; the route
`/users/{user_id}/sessions` would put what the client wrote into the path under the same
name — and a route middleware that compares the two would compare the path with itself.
Such a request ends in a 500 before the route's middleware runs, and the `error` hook gets
a `RouterException` that names the placeholder (`{user_id} in /users/{user_id}/sessions`,
in `getDebugMessage()`). That holds for every attribute the request has when the route is
found — set by a middleware for every request, or by the code that called `handle()` —
also for one whose value is `null`. Keep what a middleware learned under a key no
placeholder can have: a placeholder name is made of ASCII letters, digits and
underscores, so `Identity::class` (`App\Auth\Identity`) never collides. The parameters
stay complete in `_route_params` and in `RouteMatch::$params`; `_route_params` itself is
the router's and is set anew for each route that is found.

## Route Groups

```php
$r->group('/api', function (RouteCollector $r) {
    $r->group('/v1', function (RouteCollector $r) {
        $r->get('/users', [UserController::class, 'index']);
    });
});
// → /api/v1/users
```

In the trailing slash mode `strict` (the default), `/api` and `/api/` are two addresses:
inside `group('/api', …)`, `get('')` — exactly the empty string, for any method (`post('')`,
`redirect('')`, …) — registers the group's own address `/api`, and `get('/')` registers
`/api/` (so does a pattern of blanks alone, as before). (Up to 2.1.1 both registered
`/api/`.) In the mode `ignore` both are `/api`.

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
group. A `middlewareGroup()` takes attributes as its third argument, for routes that share
both (see [Middleware](#middleware)). See [Looking a Route Up](#looking-a-route-up) for
where the route comes from.

A route is set up in the routes file and read afterwards. Once the route table is built
(the first request, `match()` or `url()`), it is frozen: `attribute()`, `middleware()`,
`name()` and assigning `$route->attributes`, `$route->middleware` or `$route->name` throw a
`RouterException`. The same `Route` object serves every request after that — in a worker
process (RoadRunner, FrankenPHP, `handle()` in a loop) a value one request wrote onto it was
what the next request read. Keep what belongs to one request in a request attribute. The
arrays are never written in place (`$route->attributes['k'] = …`, `$route->middleware[] =
…`): PHP refuses that with an `Error`, also in the routes file — use the setters.

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

// Middleware and attributes in one group — the same as an attributeGroup() around it
$r->middlewareGroup([AuthMiddleware::class], function ($r) {
    $r->get('/me', [AccountController::class, 'show']);
}, ['format' => 'envelope']);
```

Lists add up: the router's own list (`$router->middleware()`), nested groups and a route's
`->middleware()`, each call behind the one before. An entry may have a string key
(`['auth' => AuthMiddleware::class]`); a key names one middleware, and giving it a second
time — a route's `'auth'` inside a group with an `'auth'`, an inner group, a second
`$router->middleware()` call — is refused with a `RouterException` where it is written.
(Up to 2.1.1 the second one took the place of the first, and a check the route relied on
no longer ran.) The router's own list and a route's list are kept apart: a key in both is
two middleware, the router's runs first.

**Route parameters are available in middleware:**
```php
class OwnershipMiddleware implements MiddlewareInterface
{
    public function process($request, $handler): ResponseInterface
    {
        $orderId = $request->getAttribute('id');              // from the path: what the client wrote
        $user = $request->getAttribute(Identity::class);      // from your auth middleware: who it is

        if (!$this->orders->belongsTo($orderId, $user)) {
            return Response::forbidden();
        }

        return $handler->handle($request);
    }
}
```

The identity comes from an attribute your auth middleware set under a class name, never
from a placeholder: a route parameter cannot take the place of an attribute that is set
already (see [Accessing Parameters](#accessing-parameters)), and a placeholder cannot be
named like a class.

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

    // A CORS preflight asks for one method: look the route of that method up and take its
    // word alone — the route at METHOD_NOT_ALLOWED is one of the path, not the asked one
    if ($request->getMethod() === 'OPTIONS' && $match->status === RouteMatch::METHOD_NOT_ALLOWED) {
        $wanted = $request->getHeaderLine('Access-Control-Request-Method');
        $target = in_array($wanted, $match->allowedMethods(), true)
            ? $this->router->match($request->withMethod($wanted))   // the Router, injected
            : null;

        if ($target?->route?->getAttribute('cors') === true) {
            return $this->preflight($wanted);
        }
    }

    return $handler->handle($request);
}
```

The order, from the outside in: middleware added to the router (first added = outermost),
then the groups from the outermost to the innermost (`middlewareGroup()`), then the
route's own (`->middleware()`); on the way out the other way round.

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
response no longer passes through the middleware.

**`handle()` never throws.** Where the error handler gives no response, the router's own
500 is built by the responder (`Response::setResponder()`); when that one throws as well,
the answer is a plain-text `500 Internal Server Error` that depends on nothing the
application can replace. The error handler is asked before that plain answer, and never
about its own answer — a server that wants its own last answer builds it there, without
the responder. Every exception on the way goes to the `error` hook, each once: the one
that started it, what the error handler threw, what the responder threw. (The same holds
for the odd ends: a response object that refuses to lose its body for a HEAD request, a
request object whose getters throw.)

From the outside in: middleware for every request → error handler → 404/405, or route
middleware → handler.

## Looking a Route Up

```php
$match = $router->match($request);     // nothing runs: no middleware, no handler, no routing hook

$match->status;            // RouteMatch::FOUND | NOT_FOUND | METHOD_NOT_ALLOWED
$match->route;             // the Route; at METHOD_NOT_ALLOWED a route of the path (see below)
$match->params;            // ['id' => '5'] — as in the path, not cast yet
$match->allowedMethods();  // every method the path is registered with
$match->path;              // the path the table was asked with (decoded, without basePath);
                           // as requested where the table was not asked: outside the
                           // base path (decoded), with a hidden separator, a control
                           // character or a dot segment (as it came)
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
otherwise that of the first allowed method. Tell the cases apart by `status`. Its attributes
are those of that route, not of the method the preflight asks about: a `cors` flag on
`GET /account` is no permission for `POST /account`. A preflight that reads the flag from
`$match->route` and answers with all of `allowedMethods()` frees every method of the path —
look up the route of the method in `Access-Control-Request-Method` instead
(`$router->match($request->withMethod($wanted))`).

## Named Routes & URL Generation

```php
$r->get('/users/{id}', [UserController::class, 'show'])
    ->name('user.show');

// Generate URL
$url = $router->url('user.show', ['id' => 5]);
// → /users/5

// Absolute URL (needs 'baseUrl' in the config, setBaseUrl(), or APP_URL with Router::fromEnv())
$url = $router->absoluteUrl('user.show', ['id' => 5]);
// → https://example.com/users/5
```

A name belongs to one address: two routes of different patterns with the same name end the
building of the route table with a `DuplicateRouteException` (routes of one pattern may share
a name — `get('/login')` and `post('/login')` as `login`) (the first request is a 500 and reported, `url()`
and `match()` throw), naming both patterns in `getDebugMessage()` — `url()` used to give the
address of whichever came last. A name that no route has throws a `RouteNotFoundException`;
its message names only the name asked for, its `getDebugMessage()` lists every route name
of the application — keep it out of responses and logs that others read.

Values are encoded (`rawurlencode()`, always — see `urlEncoding`), and so is the literal
text of route pattern and base path (`/my app/{x}` goes out as `/my%20app/…`; what a path
may contain as it is — `:`, `@`, `!$&'()*+,;=` — stays). `url()` returns an address only
when it leads back to its route with exactly the values you
passed — the path as the router would see it has to match the route's pattern, each
placeholder taking its value, and each value has to pass the cast of its type. A value
that does not fit throws (`12a` or `01` for `{id:int}`, an empty value, a slash where one
segment is expected), and so do values that the pattern would split differently — in the
trailing slash mode `ignore` also a value that ends in a slash, which the router drops
before it looks the route up. What `url()` does not look at: whether another route takes
the same path first (a static `/users/me`, or a dynamic route defined before this one,
in front of `/users/{id}` with `id => 'me'`). A slash that the placeholder takes
(`{path:any}`) stays a slash, each segment encoded on its own — the router refuses `%2F`.
`null` is no value. And the address as a whole has no `.` or `..` segment, no path that
begins with `//` below the base path, begins with a single `/` and contains no backslash,
whatever route pattern and base path are made of. Parameters that are not placeholders of
the route are left out without a word — a misspelled key is not noticed: `url()` writes no
query string (append one yourself, e.g. with
`http_build_query()`):

```php
$router->url('files', ['path' => 'my dir/a b.txt']);   // /files/{path:any} → /files/my%20dir/a%20b.txt
$router->url('user.show', ['id' => 'a/b']);            // RouterException: the placeholder is one segment
$router->url('post.show', ['id' => '12a']);            // /posts/{id:int} → RouterException: the address would end in 404
$router->url('files', ['path' => '../secret']);        // RouterException: a client would resolve the '..'
$router->url('export', ['name' => '..']);              // /export/{name}.json → /export/...json: no segment of its own, fine
$router->url('files', ['path' => 'a\\b']);             // RouterException: no route accepts a backslash
$router->url('page', ['path' => '/evil.example/x']);   // /{path:any} → RouterException: an empty segment (and '//' would name a host)
```

## Redirect Routes

```php
$r->redirect('/old-url', '/new-url');           // 302 Temporary
$r->redirect('/old-url', '/new-url', 301);      // 301 Permanent
$r->redirect('/users/{id}/profile', '/profile/{id}');  // With parameters
```

A placeholder in the target is `{name}` — nothing else in braces — and has to exist in the
source (the prefix of its groups included). The status is one a client follows — 301,
302, 303, 307 or 308 (`Response::REDIRECT_STATUSES`; not 300, 304 or 305) —, and the target
has no control character other than a tab; both are refused where the route is written.
A placeholder must not stand where scheme or host belong: a target that has a scheme (a
letter, then letters, digits, `+`, `-`, `.`, then `:`) or begins with `//` writes scheme
and host before its first placeholder, the host not empty and closed by `/`, `?` or `#`
(`'https://app.example/{path}'`, `'//cdn.example/{path}'`); anything else
(`'https:{path}'`, `'https:///{path}'`, `'//{host}/x'`, `'https://app.example{path}'`,
`'{scheme}:{path}'`, and also `'https:///fixed.example/{path}'` with its empty host in
front of the fixed one) is refused where the route is written — read as a browser reads
it, so a backslash counts as a slash. A target without scheme and host (`'/new/{path}'`,
`'docs/{path}'`, `'?next={path}'`, `'1:relative/{path}'`) stays on the address the client
is at.

A value goes in encoded as a whole (`rawurlencode()`): its slashes become `%2F`, so it
cannot change scheme or host of an accepted target by what it contains. What it renders
as is checked once more when the redirect goes out: an address that would change scheme
or host — an empty value in front of a slash, `'/{a}/{b}'` with `a` empty or `false`
giving `//evil.example` — is not sent; the request ends in a 500 and the `error` hook gets
the `RouterException`. The check compares the target as written with the address as
rendered, not with the host the request came to: an empty value in front of a slash ends
in a 500 also where the rendering would name the application's own host
(`//app.example`). Nor is an address sent where a value would be — or make, with the text
around it — a `.` or `..` segment of the path: `'/docs/{x}/'` with `..` (a client would ask
for `/`), `'/a/%2e{x}'` with `.`; a `..` the target writes itself (`'../{x}'`) and dots that
are no segment of their own (`'/dl/{x}.json'` with `..`, a value `...`) go out. Encoded as
a whole, `redirect('/old/{path:any}', '/new/{path}')` sends `/old/docs/intro` to
`/new/docs%2Fintro` — a path this router answers with 404. For redirects that keep the
segments of a path, use a route or a handler of your own.

The rules belong to `RedirectHandler` itself: one built by hand (`new RedirectHandler($to,
$status)`, as a handler of a route of your own) refuses the same targets in its
constructor, and checks what it renders in the same way.

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

`paginated()` throws an `InvalidArgumentException` for a page below 1, a total below 0, a
`perPage` below 1, and a page whose last item would be beyond the largest integer. Check
a page number that comes from the request (`?page=0`) before you pass it on — through
`handle()` the exception is a 500.

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
Response::tooManyRequests(60);                             // 429 with Retry-After (0 or more seconds)
Response::serverError();                                   // 500
```

### Other Responses

```php
Response::html($content);                                // text/html
Response::html($content, 404);                           // text/html with status
Response::text($content);                                // text/plain
Response::redirect('/new-url');                          // 302
Response::redirect('/new-url', 301);                     // 301 — 301, 302, 303, 307 or 308, or a RouterException
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
environment. A key the router does not know is refused with a `RouterException` that
names the known ones (see [Options](#options)), so that a typo like `basepath` does not go
unnoticed.

### Via Environment Variables

```php
$router = Router::fromEnv();                       // everything from the environment
$router = Router::fromEnv(['debug' => false]);     // a key you pass wins over its variable
```

`fromEnv()` is the one place where the router reads the environment (`$_ENV`, then the
environment of the process), and these are all the variables it reads:

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
`yes`/`no`) or empty — and `ROUTER_URL_ENCODING` has to mean on (empty meant off and is
refused like `false`, see [Options](#options)) —, `ROUTER_TRAILING_SLASH` has to be
`strict`, `ignore` or empty (empty means the default), `ROUTER_BASE_PATH` written decoded;
anything else makes `fromEnv()` throw a `RouterException` that names the variable.
`APP_ENV` means nothing to the router.

What a web server hands over with each request — nginx's `fastcgi_param`, Apache's
`SetEnv` — is not the environment of the process: under PHP-FPM it reaches `fromEnv()` only
where PHP copies request parameters into `$_ENV` (`variables_order` with `E`; not so with
`php.ini-production`). (Under classic CGI the request's variables *are* the environment of
the process, so they keep arriving.) Set the pool's `env[NAME]` (with FPM's default `clear_env = yes`
that is the only environment a worker has), a real environment variable, or pass the
value in the config array.

### Via Fluent API

```php
$router = Router::create()
    ->setDebug(true)
    ->setBasePath('/api')
    ->setBaseUrl($env['APP_URL'] ?? null);   // known only once the .env is read

$router->isDebug();   // what the router decided
```

`setDebug()`, `setBasePath()`, `setBaseUrl()` and `loadRoutes()` belong in front of the
first request, `match()`, `url()` or `absoluteUrl()`: those build the route table. Once it
is built they throw a `RouterException` instead of being accepted without effect. (A first
use that could not build it — no routes loaded, a routes file that throws — leaves them
open.)

### Options

| Config Key | Variable read by `fromEnv()` | Default | Description |
|------------|--------------|---------|-------------|
| `debug` | `APP_DEBUG` | `false` | Enable debug mode (detailed errors). Boolean or boolean-like (`'true'`, `'0'`, ...). `null` means the default; `''` and `0` count as off; anything else is refused |
| `basePath` | `ROUTER_BASE_PATH` | `''` | URL prefix for all routes (`/api`, `/api/` and `api` mean the same), a string (`null` is the default; another type is refused — `false` as well, what `getenv()` gives without the variable: write `getenv(…) ?: null`). Written decoded, like a route pattern (`/my app`, not `/my%20app`): a percent-encoded character, a backslash, a control character, `?`, `#` or a `.`/`..` segment is refused |
| `baseUrl` | `APP_URL` | `null` | Base URL for `absoluteUrl()`, put in front of the address as it is (a slash at its end is dropped): `http://` or `https://`, a host, a port and a path at most (`https://app.example`, `https://app.example:8443/base`). PHP's WHATWG parser (as a browser reads it) has to take it with a host; how it writes the host does not matter (`https://Bücher.example`, `https://[0:0:0:0:0:0:0:1]` are taken as written). Empty means none: `null`, `''` and, as in 1.x, `false` (`getenv()` without the variable), `0` and `'0'`; another type is refused, and so is a string with a control character or a blank, without scheme or host (`example.com`, `//app.example`), with another scheme, user information, a backslash (`https://evil\@trusted.example` is the host `evil` for a browser), an authority that is no host (`https://:443`), a port that is empty or outside 1–65535, a query or a fragment. Also `setBaseUrl()`; a `UrlGenerator` built by hand applies the same rule in its `setBaseUrl()` (`null` or `''` for none) |
| `trailingSlash` | `ROUTER_TRAILING_SLASH` | `'strict'` | `'strict'` or `'ignore'`; `null` and `''` mean the default, anything else is refused |
| `urlEncoding` | `ROUTER_URL_ENCODING` | `true` | `rawurlencode()` parameter values in `url()`/`absoluteUrl()` and check the address. Always on: a value that means off (`false`, `0`, `'off'`, `''`) is refused with a `RouterException` — off, values went out as given and every check of `url()` with them (a backslash, a control character, a `..` segment, `//` in front). The key stays for a value that means on |
| `routesFile` | - | `null` | Routes file, as `loadRoutes()` sets it — a string, or `null` for none; another type is refused |
| `implicitHead` | - | `true` | Answer `HEAD` through the `GET` route (see [HTTP Methods](#http-methods)) |
| `emitChunkSize` | - | `8192` | Bytes `run()`/`emit()` read from the response body at a time; an integer, or a string of digits, from 1024 to 16777216 (see [Memory](#memory)) |
| `emitIdleTimeout` | - | `30` | Seconds `run()`/`emit()` wait for the next byte of a body that has not ended (a read that gives `''` before the end); a number above 0 and up to 3600, a fraction as well (`0.5`), or a string of one with six decimals at most (more would be rounded before the comparison). Then the body is given up on (see [PSR-15 Compatibility](#psr-15-compatibility)) |

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
    // $data: method, path, exception, status — or type ('emit'), message, exception, status.
    // method and path are '' where the request object itself could not say
    $logger->error("Error", $data);
});
```

`status` in the `error` hook (always the last key) is the status the router answers with
for what it reports, as it stands when it reports it: 400 for a request it cannot use (a
parameter that cannot be cast, a request the PSR-7 objects refuse in `run()`), 500 for
everything else it answers itself (also a request `run()` cannot build for another reason).
Each report has its own: a responder that fails at the 400 for a parameter is reported with
500, because `Router::handle()` takes it as every failure (the error handler, else a 500) —
the parameter's report keeps its 400. (A `RouteDispatcher` used on its own without
`setErrorResponder()` lets that exception out, as every failure.) In `run()`, a responder
that fails at the answer to a request it cannot read is reported with that answer's status,
which then goes out as plain text. What an error handler (`setErrorHandler()`) answers
instead is its own; the hook was called before it. Two cases are about what already went
out, and report the status PHP has set for it: sending that fails in `run()` (a header, the
body), and type `emit` (output had started before the router could send). Where PHP keeps no
status (the CLI), that is the 200 it would send.

**Note:** Hook exceptions are caught and never affect the response. Register `hookError` to
get them; without it a line goes to stderr (`error_log()` where there is none) that names
the event, the class of the exception and the file and line it was thrown at — not its
message, which may carry what a request sent (a line break would forge a second log line).
A `hookError` callback that fails itself gets that line too. Where not even the line can
be written (`STDERR` closed), it is dropped — a failing hook never interrupts the request.

```php
$router->on('hookError', function (array $data) {
    // $data: event (the hook that failed), exception
    $logger->error("Hook failed", $data);
});
```

**Without an `error` hook nothing is logged.** An exception caught by the router becomes a
500 response and leaves no other trace — logging is the application's job, the hook is how.

**`path` and `params` are request data.** In `dispatch` and `methodNotAllowed` they are
already URL-decoded; a control character never gets there (such a path has no route), but
other characters do (`%E2%80%A8`, bytes that are not UTF-8). In `notFound` a path refused
for a [hidden separator, a control character or a dot segment](#slashes-in-a-parameter)
arrives as it came, unchanged — `%0A` stays `%0A`, and a control character that a request object of
another make handed over as it stands stays one —, base path included, also outside the
base path; any other path arrives decoded. Encode all of them before they go into a
line-based log.

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
status is the response's, whatever its headers are — PHP would turn a 403 with
`WWW-Authenticate` into a 401 and a 200 with a `Location` into a 302; a redirect is a 3xx
status (`Response::redirect()`). Status line and header lines are checked before anything
is sent: a reason phrase with a control character other than a tab, a protocol version that
is no version (a digit, and a dot and a digit for a minor one: `1.1`, `1.0`, `2`), a header
name that is no token (RFC 9110) and a header value with a control character other than a
tab are refused — PHP would drop such a status line and send its own 200, and refuse such a
header line only after the lines in front of it went out (a `Location` among them makes
the status a 302). `run()` answers 500 then (the `error`
hook gets the `RouterException`), `emit()` throws it. A read that gives `''` before the end
of the body is waited past (PSR-7 allows it while the next bytes are on their way), with a
pause that grows to 50 ms and never reaches past the deadline; a body that gives no byte
for `emitIdleTimeout` seconds (30) — counted from the first read that gave nothing; a byte
that comes after that is too late —, or ends short of its `Content-Length` after a first
byte, is given up on with a
`RouterException` once the headers are out: `run()` reports it to the `error` hook (with the
status that went out), `emit()` throws it — the client got less than the response promised.
A body longer than its `Content-Length` is sent up to it, never beyond (a kept-alive client
would read the rest as the next response), and ends the same way.
A body that sent no byte is no short one (the answer to `HEAD` keeps the `Content-Length` of
the `GET`, also through `emit($response)` as above), nor is that of a 1xx, 204 or 304. If
output has already started, nothing can be sent any more: the `error` hook is called with
`type: 'emit'`.

## Dependency Injection

```php
use Psr\Container\ContainerInterface;

$router = Router::create()
    ->setContainer($container)  // Any PSR-11 container
    ->loadRoutes(__DIR__ . '/routes.php');

// Controllers are resolved via container if available
// Otherwise instantiated directly
```

Middleware given as a class name works the same way: a name the container has is the
container's. What it returns has to be the middleware itself (a `MiddlewareInterface`) —
a factory registered in its place, or any other object, ends the request in a 500 with a
`RouterException`, instead of a middleware the router builds with its constructor's
defaults (a rate limit with another limit than the one you configured). A name the
container does not have is built directly when its constructor needs no argument.

## Wrapping the Router

All classes of the library are `final`, `Router` included (the exceptions stay open). What
1.x applications did in a subclass of `Router` has its own place now: a middleware for
every request (`middleware()`, also for a 404 page of your own), `setErrorHandler()`,
hooks, route attributes, `Router::match()`, `setBaseUrl()`. If something is missing there, it belongs in
the library — ask for it rather than building around it.

Code that receives the router types against `Sodaho\Router\Contract\RouterInterface`:
`handle()` (PSR-15), `match()`, `url()` and `absoluteUrl()`. That is also what a test
doubles — `createMock(RouterInterface::class)`; `Router` itself is final and cannot be
doubled. Setting the router up (`loadRoutes()`, `middleware()`, `app()`, `on()`,
`setErrorHandler()`, ...) stays with the one concrete `Router` an application builds.

A decorator of the interface sees what it wraps, but `run()` sends the router's own
answer: to send a decorator's, call its `handle()` and `emit()` the response (or use your
own emitter, see [PSR-15 Compatibility](#psr-15-compatibility)). A header on every answer
belongs in `middleware()`, not in a decorator.

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

Whatever is thrown while a request is handled — by a handler, a middleware, the routes
file, the error handler, the responder or the router itself — never leaves `handle()`: it
becomes a 500 response and is passed to the `error` hook (see
[Error Handler](#error-handler)). That includes `NotFoundException` and
`MethodNotAllowedException` thrown by your own code; return `Response::notFound()`
instead. `run()` adds two cases of its own. A request the PSR-7 objects do not accept — a
`Host` header with a port that is none (`Host: x:99999999`), a header value with a control
character — is answered with `400 Bad Request` before anything of the application runs,
and reported to the `error` hook as a `RouterException` "The request could not be read"
(what the PSR-7 objects said is in `getPrevious()`; anything else that fails while the
request is built from PHP's globals: 500, reported as it is). `run()` reads everything it
needs from the response before it sends the first byte: a body that was closed or
detached, a getter that throws, is a plain-text 500, reported as well — `emit()`, given
such a response directly, throws. A body that fails while it is sent is reported too;
after the headers are out, nothing can be answered any more.

A router loads its routes file once for each `loadRoutes()`. When the route table cannot
be built — the file threw, a route was refused — every request is a 500 through the
`error` hook, and the file is not `require`d again; what its callable registered on the
router itself (middleware, apps, hooks) is taken back, so that each attempt reports the
same failure. Another router, or another
`loadRoutes()`, requires it again, as 1.x did: a routes file that declares a function or a
class can be used by one router per process — in a worker that builds a router per
request, keep declarations out of it. Its closure sees the router as `$this`, as in 1.x. A
router is cloned before its first use only (a clone made then is a router of its own);
once it was used — a request, `match()`, `url()` — `clone` throws.

The message of a `RouterException` names what is wrong, not the value: a file path, the
address `url()` would have written, the route pattern are in `getDebugMessage()`. Log both
where the log is yours alone; show neither to a client.

| Exception | When |
|-----------|------|
| `RouterException` | Everything the router refuses: a route, pattern, fragment of `addPattern()`, middleware key or redirect target where it is written; a config value; a change to a route once the table is built; a placeholder named like an attribute of the request, a container entry that is no middleware, a redirect rendering that would change scheme or host or make a dot segment, a status line or header line that is none, a body that gives no byte for `emitIdleTimeout` seconds, ends short of its `Content-Length` or goes beyond it — while a request is handled each of these goes to the `error` hook, as a 500 where nothing was sent yet |
| `NotFoundException` | Never thrown by the router (it answers 404 itself); for your own code |
| `MethodNotAllowedException` | Never thrown by the router (it answers 405 itself); for your own code |
| `RouteNotFoundException` | Named route doesn't exist (URL generation); `getDebugMessage()` lists every route name |
| `DuplicateRouteException` | Same method+pattern registered twice, or two routes of different patterns with the same name (when the table is built, and in `new UrlGenerator()`) |

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
to lie under the resolved folder — symbolic links are followed and checked; a hard link
cannot be told from the file it shares its content with, so one inside the folder is
served even when that content also lies elsewhere), hidden files and
folders (a leading dot, `.well-known` included — give those a route), files that cannot be
read, file types that are not on the list (see `AppFolder::TYPES`, extend it with the
`types` option), and paths with a control character (a NUL byte, a line break; such a path
gets no start page either), a backslash, an encoded separator (`%2F`, `%5C`), an empty
segment (`//`) or a segment that ends in a dot or a space. A requested
path with a colon below the prefix is never looked up as a file (on Windows it would name
a stream of one); as a path of the app's own router — `/login/item/urn:isbn:1` — it gets
the start page. (Most PSR-7 implementations fold slashes at the very start of a path into
one before the router sees it: `//login/x` is `/login/x` then, for routes and apps alike.)

PHP sources (`php`, `phtml`, `phar`, `inc`, …) cannot be put on the list. Source maps
(`.map`) are not on it: they publish the sources of the app —
`'types' => ['map' => 'application/json']` if that is what you want.

Files go out `inline`, under the origin of the application. An SVG can carry script that
runs when the file is opened on its own — keep only files of your own in the folder (no
uploads), or send a `Content-Security-Policy` from a middleware for every request (`sandbox`
for the folder's paths), or take `svg` off the list (`'types' => ['svg' => null]`).

The folder you register is trusted as a whole: the rule for hidden names applies below
it, not to its own path, and a folder that is a link is followed (deployments switch
releases that way). Whoever can write into the folder, replace it, or rename a directory
above it decides what is served — keep all of that writable for the deployment only.

A file is resolved, then opened — and checked once more at the open file: its path still
resolves to itself inside the folder, and the file under that path is the one that was
opened (device and file number, where the system has them). Validator, length and body all
come from that one open file. A writer who puts a link to a file elsewhere in place of a
file of the folder between the two steps gets a 404 (up to 2.1.1 that file was sent).
What this cannot rule out, without system calls PHP does not have: a writer who swaps a
directory on the way for a link and back between two of the checks — another reason to
keep the folder writable for the deployment only. A hard link is the file it names, as
above.

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
its own rule under every name that leads to it.

**Conditional requests:** files go out with an `ETag` (with one exception, below). A
browser that asks again
with `If-None-Match` gets `304 Not Modified` without a body while its copy holds — so
`no-cache` costs a request, not the file. A `Range` counts for `GET` only, and one that
comes with an `If-Range` gets the whole file.

Up to 64 KiB the `ETag` is a hash of the content. That is the start page: two builds of it
often have the same size (only a hash in it differs), and a pipeline may give them the
same time — told apart by time and size alone, the browser would keep a page whose
scripts are gone. Larger files are told apart by device, file number, time of the last
change and size, and their tag is marked weak (`W/`): one that is overwritten in place by
a file of the same size and the same modification time — the same second, or a time that
`cp -p`, `rsync -t` or the build pipeline keeps — is not told apart. (A release switched
by a link, or a file moved into place, is. Where the system reports no file numbers,
large files go out without an `ETag` and are sent again every time.) No
`Last-Modified` goes out and `If-Modified-Since` is not answered: a date cannot tell two
such start pages apart either.

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

A middleware that rewrites a page of the app on its way out — a CSP nonce in the start
page — has to work on the whole page, never on a 304 or a piece of it: the browser would
keep the old body under the new header. Which request ends at a page only the folder
knows (the start page has many paths and may have another name), so the middleware goes
by the answer — but only where no route matched, so that no handler of yours runs twice:
where a page of its app comes back as 304 or 206, it asks once more without the
condition. (An app with a more specific prefix below it — `/login/help` — would be
covered as well; exclude it the same way.) **Register it last**, so that it stands innermost and no other middleware runs
twice either. And what it makes is made for one request: no validator, not the length of
the file, not to be stored.

```php
$match = $request->getAttribute(RouteMatch::class);
$folder = in_array($request->getMethod(), ['GET', 'HEAD'], true)
    && $match->status === RouteMatch::NOT_FOUND           // no route: only an app folder has a file for this
    && str_starts_with($match->path . '/', '/login/');    // this app, not another one (/admin, /)
$isPage = static fn (ResponseInterface $r): bool =>
    strtolower(trim(explode(';', $r->getHeaderLine('Content-Type'))[0])) === 'text/html';

$response = $handler->handle($request);

if ($folder && $isPage($response) && in_array($response->getStatusCode(), [304, 206], true)) {
    $response = $handler->handle($request->withoutHeader('If-None-Match')->withoutHeader('Range'));
}
if (!$folder || !$isPage($response) || $response->getStatusCode() !== 200) {
    return $response;                                     // routes, assets and their 304, a 404: as they are
}
// ... rewrite the body ...
return $rewritten
    ->withoutHeader('ETag')->withoutHeader('Content-Length')
    ->withHeader('Cache-Control', 'no-store');
```

`$match->path` is the path without the base path — except for a request outside the base
path (and one with a hidden separator, a control character or a dot segment), where it is the whole path: with a base path,
compare against the request's own path instead. And with an app at `/` every `GET` path belongs to an app; there is nothing
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

The router uses `JsonResponder` by default. You can swap it for RFC 9457 (Problem Details,
which replaced RFC 7807) or a JSON format of your own:

```php
use Sodaho\Router\Response;
use Sodaho\Router\Service\RfcResponder;

// RFC 9457 Problem Details format
Response::setResponder(new RfcResponder('https://api.example.com/errors'));

// Error responses now use RFC 9457 ("type" from the error code, "title" from the
// message, "status" the status code of the response — never what the details say):
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

class ApiResponder implements ResponderInterface
{
    public function formatSuccess(mixed $data, ?string $message = null, ?array $meta = null): array
    {
        return ['data' => $data] + ($meta !== null ? ['meta' => $meta] : []);
    }

    public function formatError(string $message, ?string $code = null, ?array $details = null): array
    {
        return ['errors' => [['title' => $message, 'code' => $code, 'meta' => $details]]];
    }

    public function getContentType(): string
    {
        return 'application/vnd.api+json';  // Used for 4xx/5xx responses
    }

    public function getSuccessContentType(): string
    {
        return 'application/vnd.api+json';  // Used for 2xx responses
    }
}
```

A responder shapes the array; the body is always that array **encoded as JSON**, sent with
the content type the responder names. It cannot produce XML or another format that is not
JSON — `application/xml` would only label a JSON body. Build such responses yourself
(`Response::text()`, or a PSR-7 response of your own). A responder is not told the status
of the response; for `RfcResponder` the router adds `status` itself — and the details of an
error cannot replace `type`, `title` or `status` (a 400 whose details say `"status": 200`
goes out with `"status": 400`; `detail`, `instance` and every other key are taken).

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
| Optional segments `[/suffix]` | Complexity vs. benefit. Define two routes instead. Refused when the route is registered — and with them every `[` or `]` in a route pattern. |
| Regex in route patterns | Use predefined patterns or `addPattern()`. Refused when the route is registered. |
| Literal `{`, `}`, `?`, `#`, `%XX` in a route pattern | A pattern is a path, written decoded; braces are placeholders. Refused when the route is registered. |
| Route priority/ordering | A static route (no placeholder) always wins, whatever the order of definition: `/users/me` beats `/users/{name}`. Among routes with placeholders the first one defined that matches wins — define the specific ones first. |
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

Within one route, the work grows with the length of the path — except for two placeholders
that take slashes (`/{a:any}/{b:any}`, or a pattern of your own that takes one): PCRE tries
every way to split the path between them, and the work grows with the square of its
segments. With the default `pcre.backtrack_limit` (1,000,000) a path of some 800 segments
to such a route reaches the limit, and the request is answered with 500 and reported to the
`error` hook (never treated as "no match"). Keep one placeholder that takes slashes per
route, or put a fixed segment between them that the path names once.

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

### Checklist for an Authentication Server

- **Register an `error` hook.** Without one, an exception becomes a 500 and leaves no trace
  (see [Hooks](#hooks-logging)).
- **Rewrite before you guard.** A middleware for every request that changes method or path
  (a method override, a stripped prefix) has the route looked up again for what lies
  further in — the middleware before it saw the route of the request as it came. Add a
  rewriting middleware first (outermost), so that a guard behind it decides about the
  route that runs.
- **Keep identities under class-name keys** (`Identity::class`). A route parameter never
  replaces an attribute that is set (the request ends in a 500), and no placeholder can be
  named like a class.
- **Build absolute links from `absoluteUrl()`** with a configured `baseUrl` (`APP_URL`).
  `run()` builds the request's URI with scheme and host from what the client sent (`Host`,
  `X-Forwarded-Proto`); a reset or login link made from `$request->getUri()` points where
  the client wants.
- **A CORS flag at 405 belongs to another route** — see [Looking a Route Up](#looking-a-route-up).
- **Keep GET handlers free of side effects** or give them a `head()` route: HEAD runs the
  GET route (`implicitHead`), and link scanners ask with HEAD. A dynamic `head()` no longer
  shields a static GET route — give that route a static `head()` of its own, or turn
  `implicitHead` off. Token-consuming links belong behind a POST.
- **Take a `{path:any}` value for a file only after `realpath()` and a prefix check** — the
  router keeps `..`, `.` and empty segments out, not every name a file system understands.
- **Limit the length of a request line in the web server.** The router has no limit of its
  own: nginx `large_client_header_buffers` (8k per line by default), Apache
  `LimitRequestLine` (8190).
- **Route names in exceptions:** `RouteNotFoundException::getDebugMessage()` lists every
  route name — keep debug messages out of responses.

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
