<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sodaho\Router\Exception\RouterException;

/**
 * A folder with a built web app (index.html plus assets), served under a path prefix.
 *
 * Registered with Router::app(). It answers only where the route table has nothing: an
 * existing file of the folder, or the app's start page for every other path under the
 * prefix (the client-side router of a single-page app takes over from there) — unless the
 * path looks like a file that is missing; then it is a 404, so that a missing script does
 * not come back as HTML.
 *
 * What it never serves, whatever the folder contains: anything outside the folder (the
 * resolved file has to lie under the resolved folder, links included), hidden files and
 * folders (a leading dot), files that cannot be read, file types that are not on the list
 * (PHP sources cannot be put on it), and paths with a NUL byte, a backslash, an encoded
 * separator or a segment that ends in a dot or a space.
 */
final class AppFolder
{
    /**
     * Extension => Content-Type. Only files with one of these extensions are served (the
     * 'types' option adds to the list or takes off it).
     */
    public const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'wasm' => 'application/wasm',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
    ];

    /**
     * Extensions of PHP sources (php, php8, phtml, pht, phps, phpt, phar, inc). Never
     * served, and the 'types' option cannot put them on the list.
     */
    private const NEVER = '/^(?:php\d*|phtml|pht|phps|phpt|phar|inc)$/';

    /**
     * For the 'immutable' option: the requested paths (below the prefix) of what a bundler
     * hashed — files in assets/ (Vite, Rollup, esbuild) or static/ (webpack, Create React
     * App) whose name ends, before the extension, in a hyphen and exactly eight characters
     * or in a dot and eight to 32 hexadecimal digits: assets/index-B1fQx9cD.css,
     * assets/app.4f9a2b1c.js, static/js/main.a1b2c3d4.chunk.js.
     *
     * It goes by form, and a form proves nothing: assets/app-settings.js and
     * assets/user-12345678.png have it too. That is why no file counts as unchanging
     * unless the application says so — pass this constant where the two directories hold
     * nothing but the bundler's output.
     */
    public const HASHED = '~^(?:assets|static)/(?:[^/]+/)*[^/]+(?:-[A-Za-z0-9_-]{8}|\.[0-9a-f]{8,32})(?:\.chunk)?\.[A-Za-z0-9]+$~';

    private const OPTIONS = ['index', 'types', 'immutable', 'cacheIndex', 'cacheImmutable', 'cacheOther'];

    /** Path prefix without trailing slash; '' for the root */
    public readonly string $prefix;

    private readonly string $directory;
    private readonly string $index;

    /** @var array<string, string> */
    private readonly array $types;

    private readonly ?string $immutable;
    private readonly ?string $cacheIndex;
    private readonly ?string $cacheImmutable;
    private readonly ?string $cacheOther;

    /**
     * @param string $prefix Path prefix ('/login', '/' for the root)
     * @param string $directory The folder with index.html
     * @param array<string, mixed> $options Checked here, key by key:
     *                                      index: name of the start page (default 'index.html') ·
     *                                      types: extension => Content-Type to add, null to take one off the list
     *                                      (source maps — 'map' — are not on it; PHP sources cannot be put on it) ·
     *                                      immutable: regular expression for requested paths (below the prefix, without
     *                                      leading slash) whose files never change, e.g. AppFolder::HASHED; default
     *                                      null: none ·
     *                                      cacheIndex / cacheImmutable / cacheOther: Cache-Control for the start page
     *                                      ('no-cache'), for immutable names ('public, max-age=31536000, immutable') and
     *                                      for everything else ('no-cache'); null sends no Cache-Control
     *
     * @throws RouterException If the folder does not exist or an option is not understood
     */
    public function __construct(string $prefix, string $directory, array $options = [])
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            throw new RouterException(
                'Unknown app option. Known options: ' . implode(', ', self::OPTIONS),
                debugMessage: 'Unknown: ' . implode(', ', array_map(strval(...), $unknown)),
            );
        }

        $segments = array_values(array_filter(explode('/', $prefix), static fn (string $s): bool => $s !== ''));
        foreach ($segments as $segment) {
            if (!self::isPlainSegment($segment)) {
                throw new RouterException('App prefix must be a plain path', debugMessage: $prefix);
            }
        }
        $this->prefix = $segments === [] ? '' : '/' . implode('/', $segments);

        // A relative path means the working directory of this moment, not that of a later
        // request. Made absolute by name only: a link stays a link and may be repointed.
        $absolute = self::bind($directory, getcwd());
        if (!is_dir($absolute)) {
            throw new RouterException('App folder is not a directory', debugMessage: $directory);
        }
        $this->directory = $absolute;

        $index = $options['index'] ?? 'index.html';
        // No colon either: the start page is a file, and a path with a colon is never one
        if (!is_string($index) || !self::isPlainSegment($index) || str_contains($index, ':')) {
            throw new RouterException("App option 'index' must be a file name");
        }
        $this->index = $index;

        $types = self::TYPES;
        $given = $options['types'] ?? [];
        if (!is_array($given)) {
            throw new RouterException("App option 'types' must map extensions to content types");
        }
        foreach ($given as $extension => $type) {
            if (!is_string($extension) || preg_match('/^[a-z0-9]+$/D', $extension) !== 1 || ($type !== null && !self::isHeaderValue($type))) {
                throw new RouterException("App option 'types' must map extensions (lowercase, without dot) to content types");
            }
            if ($type !== null && preg_match(self::NEVER, $extension) === 1) {
                throw new RouterException("App option 'types' cannot put PHP sources on the list", debugMessage: $extension);
            }
            if ($type === null) {
                unset($types[$extension]);
            } else {
                $types[$extension] = $type;
            }
        }
        $this->types = $types;

        $immutable = $options['immutable'] ?? null;
        if ($immutable !== null && (!is_string($immutable) || @preg_match($immutable, '') === false)) {
            throw new RouterException("App option 'immutable' must be a regular expression or null");
        }
        $this->immutable = $immutable;

        $this->cacheIndex = self::cacheControl($options, 'cacheIndex', 'no-cache');
        $this->cacheImmutable = self::cacheControl($options, 'cacheImmutable', 'public, max-age=31536000, immutable');
        $this->cacheOther = self::cacheControl($options, 'cacheOther', 'no-cache');
    }

    /**
     * The folder as it is registered: a relative name bound to the working directory of
     * this moment, not to that of a later request. Bound by name only — a link stays a
     * link and may be repointed.
     *
     * What counts as absolute is the system's matter. Where '/' is the separator, only a
     * leading '/' does; 'C:/site' and '\site' are relative names there. On Windows only a
     * path with a drive ('C:\site', 'C:/site') or a server ('\\server\share') does; one
     * that is bound half — '\site' (root of the current drive), 'C:site' (working
     * directory of that drive) — is refused rather than guessed at.
     *
     * @internal
     *
     * @param string|false $cwd The working directory, false when there is none (it was removed)
     *
     * @return string The absolute path — or '' for a name that cannot be bound: an empty
     *                one (it is not the working directory), a relative one without a
     *                working directory, a half-bound one
     */
    public static function bind(string $directory, string|false $cwd, string $separator = DIRECTORY_SEPARATOR): string
    {
        [$bound, $halfBound] = $separator === '/'
            ? ['~^/~', '~^(?!)~']
            : ['~^(?:[A-Za-z]:[/\\\\]|[/\\\\]{2})~', '~^(?:[/\\\\]|[A-Za-z]:)~'];

        return match (true) {
            preg_match($bound, $directory) === 1 => $directory,
            $directory === '', $cwd === false, preg_match($halfBound, $directory) === 1 => '',
            default => $cwd . $separator . $directory,
        };
    }

    /**
     * Whether a path (decoded, without the router's base path) lies under this app's prefix.
     */
    public function owns(string $path): bool
    {
        return $this->prefix === '' || $path === $this->prefix || str_starts_with($path, $this->prefix . '/');
    }

    /**
     * The response for a request under this app's prefix — or null: nothing to serve, the
     * router answers 404.
     *
     * @param string $path The request path as the route table sees it (decoded, without base path)
     */
    public function serve(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        $method = $request->getMethod();
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $relative = $this->relativePath($request->getUri()->getPath(), $path);
        if ($relative === null) {
            return null;
        }

        // PHP remembers resolved paths (realpath cache, two minutes by default). A folder
        // that is replaced while the process lives must not be served from memory.
        clearstatcache(true);

        $root = realpath($this->directory);
        if ($root === false) {
            return null;
        }

        // A colon names a stream of a file on Windows. Such a path is never looked up as a
        // file; as a path of the app's own router (/item/urn:isbn:1) it stays valid.
        $startPage = false;
        $file = str_contains($relative, ':') ? null : $this->fileFor($root, $relative, $startPage);

        if ($file === null) {
            // Nothing there. A path that looks like a file stays missing; every other path
            // belongs to the app's own router and gets the start page.
            $last = (string) strrchr('/' . $relative, '/');
            if (str_contains($last, '.')) {
                return null;
            }

            $file = $this->fileFor($root, '', $startPage);
        }

        if (!is_string($file)) {
            return null;
        }

        $type = $this->types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        if ($type === null) {
            return null;
        }

        $range = $request->getHeaderLine('Range');
        $response = Response::file($file, null, $type, true, $range === '' ? null : $range)
            ->withoutHeader('Content-Disposition');

        // The browser caches by address: what was asked for decides, not where a link led
        $cache = match (true) {
            $startPage => $this->cacheIndex,
            $this->immutable !== null && preg_match($this->immutable, $relative) === 1 => $this->cacheImmutable,
            default => $this->cacheOther,
        };

        return $cache === null ? $response : $response->withHeader('Cache-Control', $cache);
    }

    /**
     * The part of the path below the prefix, without leading or trailing slash — or null
     * when the path is one this class refuses to look at.
     */
    private function relativePath(string $rawPath, string $path): ?string
    {
        // An encoded slash has no business in the path of a static file: the router decodes
        // it into a separator, whoever sits in front (proxy, access rules) may not have.
        // Backslash and NUL — encoded or not — are refused segment by segment below.
        if (preg_match('/%2f/i', $rawPath) === 1) {
            return null;
        }

        // An empty segment anywhere — the router's "ignore" mode has trimmed those at the
        // end before this class sees the path
        if (str_contains($rawPath, '//')) {
            return null;
        }

        $relative = (string) substr($path, strlen($this->prefix));
        $relative = str_starts_with($relative, '/') ? substr($relative, 1) : $relative;
        $relative = str_ends_with($relative, '/') ? substr($relative, 0, -1) : $relative;

        if ($relative === '') {
            return '';
        }

        foreach (explode('/', $relative) as $segment) {
            if (!self::isPlainSegment($segment)) {
                return null;
            }
        }

        return $relative;
    }

    /**
     * The file to send for a path below the folder: the file itself, or the start page of
     * the directory it names.
     *
     * @param bool $startPage Set here: whether the path asks for a start page — a
     *                        directory, or the start page by its name
     *
     * @return string|false|null The resolved file · false: something is there that must
     *                           not be served · null: nothing is there
     */
    private function fileFor(string $root, string $relative, bool &$startPage): string|false|null
    {
        $target = realpath($relative === '' ? $root : $root . DIRECTORY_SEPARATOR . $relative);
        if ($target === false) {
            return null;
        }

        $startPage = is_dir($target) || $this->isStartPage($root . DIRECTORY_SEPARATOR . $relative, $target);

        if (is_dir($target)) {
            $target = realpath($target . DIRECTORY_SEPARATOR . $this->index);
            if ($target === false) {
                return null;
            }
        }

        // A file that cannot be read is treated like one that must not be served: 404, not 500
        if (!str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_file($target) || !is_readable($target)) {
            return false;
        }

        // The resolved way counts as well: a link must not lead to something hidden
        foreach (explode(DIRECTORY_SEPARATOR, substr($target, strlen($root) + 1)) as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        return $target;
    }

    /**
     * Whether the file a path led to is the start page of the path's directory.
     *
     * Decided by the file, not by the spelling of its name: where the file system folds
     * case or normalizes Unicode, INDEX.HTML and other spellings lead to the same file —
     * and so does a link. The name counts as well (without regard to ASCII case), for
     * systems that report no file number.
     */
    private function isStartPage(string $path, string $target): bool
    {
        $index = dirname($path) . DIRECTORY_SEPARATOR . $this->index;
        $a = is_file($index) ? stat($index) : false;
        $b = stat($target);
        $sameFile = $a !== false && $b !== false && $a['ino'] !== 0 && $a['ino'] === $b['ino'] && $a['dev'] === $b['dev'];

        return $sameFile || strcasecmp(basename($path), $this->index) === 0;
    }

    /**
     * A path segment that names something visible, and names the same thing on every file
     * system: not empty, no leading dot ('.', '..', hidden names), no separator, no NUL, no
     * dot or space at its end (Windows drops both).
     */
    private static function isPlainSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment[0] !== '.'
            && strpbrk($segment, "/\\\0") === false
            && !str_ends_with($segment, '.')
            && !str_ends_with($segment, ' ');
    }

    /**
     * Whether a value from the options can go out as a header line as it is: a string with
     * more than blanks in it and without control characters. Refused at registration, not
     * at the first request.
     *
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function isHeaderValue(mixed $value): bool
    {
        return is_string($value) && trim($value, ' ') !== '' && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function cacheControl(array $options, string $key, string $default): ?string
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }

        $value = $options[$key];
        if ($value !== null && !self::isHeaderValue($value)) {
            throw new RouterException(sprintf("App option '%s' must be a Cache-Control value or null", $key));
        }

        return $value;
    }
}
