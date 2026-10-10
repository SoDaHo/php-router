<?php

declare(strict_types=1);

namespace Sodaho\Router;

use Nyholm\Psr7\Response as Psr7Response;
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
 * (PHP sources cannot be put on it), and paths with a control character (a NUL byte, a
 * line break), a backslash, an encoded separator or a segment that ends in a dot or a
 * space. Such a path gets no start page either.
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

    /** Up to this size (64 KiB) the ETag of a file is a hash of its content */
    private const ETAG_OF_CONTENT = 65536;

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

        // Opened once and checked at the handle: what goes out — validator, length, body —
        // is read from the file that was checked, not from one put in its place since
        $handle = self::openWithin($root, $file);
        if ($handle === null) {
            return null;
        }

        // The browser caches by address: what was asked for decides, not where a link led
        $cache = match (true) {
            $startPage => $this->cacheIndex,
            $this->immutable !== null && preg_match($this->immutable, $relative) === 1 => $this->cacheImmutable,
            default => $this->cacheOther,
        };

        // What the browser sends back to ask whether its copy still holds
        try {
            $etag = self::etag($handle, $file);
        } catch (RouterException $e) {
            // @codeCoverageIgnoreStart
            // Not reachable in a test, see etag()
            fclose($handle);

            throw $e;
            // @codeCoverageIgnoreEnd
        }
        $headers = ($etag === null ? [] : ['ETag' => $etag]) + ($cache === null ? [] : ['Cache-Control' => $cache]);

        if (self::isNotModified($request, $etag)) {
            fclose($handle);

            // The copy holds: the headers of the file, no body. The Content-Type is named
            // so that PHP does not put its own default there.
            return new Psr7Response(304, $headers + ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
        }

        // A Range is for GET only. And one that comes with an If-Range — "this piece, if the
        // file is still the one I have" — gets the whole file: a large file's tag is made
        // of its metadata (see etag()), which cannot promise that the piece belongs to the
        // content the client compared.
        $range = $method === 'GET' && !$request->hasHeader('If-Range') ? $request->getHeaderLine('Range') : '';

        $response = Response::fileFromHandle($handle, $file, basename($file), $type, true, $range === '' ? null : $range)
            ->withoutHeader('Content-Disposition');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * The file opened for reading — or null where what the handle got is not the file the
     * folder holds under that name any more.
     *
     * The file was resolved a moment ago (realpath(), see fileFor()), and a writer of the
     * folder could have put a link to a file elsewhere in its place since: opened blindly,
     * that file went out (a race between the check and fopen()). So the file is opened
     * first and checked at the handle: its path, resolved again, is still itself inside the
     * folder (no link on the way now), and the file under that path — the link itself
     * where one stands there, lstat() — is the one the handle holds (device and file
     * number). Where the system reports no file numbers only the path is compared.
     *
     * What this cannot rule out: a writer who swaps a directory on the way for a link and
     * back between two of these calls — the folder has to stay out of reach of writers you
     * do not trust. A hard link in the folder is the file it names, wherever that lies:
     * nothing tells it apart from the file itself.
     *
     * @internal
     *
     * @param string $root The folder, resolved
     * @param string $file A file under it, resolved
     *
     * @return resource|null
     */
    public static function openWithin(string $root, string $file): mixed
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        $opened = fstat($handle);
        clearstatcache(true);
        $resolved = realpath($file);
        $named = $resolved === $file ? @lstat($file) : false;

        if ($opened === false
            || $named === false
            || !str_starts_with($file, self::below($root))
            || !self::isSameFile($opened, $named)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * Whether two stat results describe the same file: same device and file number — or,
     * where the system reports no file numbers (0 on both sides), nothing that says otherwise.
     *
     * @param array<int|string, int> $a
     * @param array<int|string, int> $b
     */
    private static function isSameFile(array $a, array $b): bool
    {
        if ($a['ino'] === 0 && $b['ino'] === 0) {
            return true;
        }

        return $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }

    /**
     * The validator of a file — or null where there is nothing to make one of.
     *
     * Up to ETAG_OF_CONTENT bytes it is a hash of the content: the start page of two builds
     * often has the same size (only a hash in it differs) and — where a pipeline pins the
     * times — the same time, and a 304 for the old one would leave the browser with a page
     * whose scripts are gone. Above that size reading the file for every request costs too
     * much: device, file number, time of the last change and size, marked weak (W/) — a
     * larger file that is overwritten in place by one of the same size and the same
     * modification time (the same second, or a time that cp -p or the pipeline keeps) is
     * not told apart. Where the system reports no file number, a large file gets no
     * validator: time and size alone would not tell two releases apart.
     *
     * No Last-Modified goes out: a date cannot tell two such start pages apart either.
     *
     * Read through the handle that is sent afterwards: what it says about size and content
     * belongs to the bytes that go out. The handle is read from its start; the response
     * seeks back before it sends (FileStream).
     *
     * @param resource $handle
     *
     * @throws RouterException When the file cannot be read
     */
    private static function etag(mixed $handle, string $file): ?string
    {
        $stat = fstat($handle);
        // @codeCoverageIgnoreStart
        // Not reachable in a test: a plain file that was just opened has a size
        if ($stat === false) {
            throw new RouterException('Cannot read file', debugMessage: $file);
        }
        // @codeCoverageIgnoreEnd

        $kind = self::validatorOf($stat['size'], $stat['ino']);
        $content = $kind === 'content' && $stat['size'] > 0 ? stream_get_contents($handle, $stat['size'], 0) : '';

        // A read that fails or comes short is not the end of the file
        // @codeCoverageIgnoreStart
        if ($content === false || ($kind === 'content' && strlen($content) !== $stat['size'])) {
            throw new RouterException('Cannot read file', debugMessage: $file);
        }
        // @codeCoverageIgnoreEnd

        return match ($kind) {
            'content' => '"' . hash('xxh128', $content) . '"',
            'metadata' => sprintf('W/"%x-%x-%x-%x"', $stat['dev'], $stat['ino'], $stat['mtime'], $stat['size']),
            default => null,
        };
    }

    /**
     * What the validator of a file is made of: 'content' (a hash; small files), 'metadata'
     * (device, file number, time, size; large files) or 'none' (large files where the
     * system reports no file number).
     *
     * @internal
     *
     * @return 'content'|'metadata'|'none'
     */
    public static function validatorOf(int $size, int $fileNumber): string
    {
        return match (true) {
            $size <= self::ETAG_OF_CONTENT => 'content',
            $fileNumber !== 0 => 'metadata',
            default => 'none',
        };
    }

    /**
     * Whether the copy the client says it has is the file as it is now: If-None-Match is
     * '*', or one of its tags is the file's — compared without regard to a W/ in front of
     * either. A tag is what stands between two quotes, commas included.
     * If-Modified-Since is not answered — see etag().
     */
    private static function isNotModified(ServerRequestInterface $request, ?string $etag): bool
    {
        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        preg_match_all('~"[^"]*"~', $ifNoneMatch, $tags);

        // '*' asks whether there is a file at all — also where the file has no tag
        return $ifNoneMatch === '*' || ($etag !== null && in_array(preg_replace('~^W/~', '', $etag), $tags[0], true));
    }

    /**
     * The part of the path below the prefix, without leading or trailing slash — or null
     * when the path is one this class refuses to look at.
     */
    private function relativePath(string $rawPath, string $path): ?string
    {
        // The dispatcher has no route for a path with a hidden separator or a control
        // character and asks no app. This class keeps its own word all the same, for
        // whoever calls it directly, by the same rule: the raw path counts, not what the
        // caller decoded it to. (The decoded path is checked segment by segment below.)
        if (RouteDispatcher::hasNoRoute($rawPath)) {
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
        $base = self::below($root);
        $target = realpath($relative === '' ? $root : $base . $relative);
        if ($target === false) {
            return null;
        }

        $startPage = is_dir($target) || $this->isStartPage($base . $relative, $target);

        if (is_dir($target)) {
            $target = realpath($target . DIRECTORY_SEPARATOR . $this->index);
            if ($target === false) {
                return null;
            }
        }

        // The resolved way counts, not the path asked for: a link must not lead out of the
        // folder or to something hidden. A file that cannot be read is treated like one that
        // must not be served: 404, not 500
        if (!self::visibleBelow($root, $target) || !is_file($target) || !is_readable($target)) {
            return false;
        }

        return $target;
    }

    /**
     * The folder as the prefix of what lies below it: with one separator at its end — also
     * where it is the root of the file system ('/', 'C:\'), which has one already: '//' as a
     * prefix matched nothing, and such a folder served no file at all.
     *
     * @internal Public for its tests: a root like 'C:\' exists on Windows only
     *
     * @param non-empty-string $separator
     */
    public static function below(string $root, string $separator = DIRECTORY_SEPARATOR): string
    {
        return rtrim($root, $separator) . $separator;
    }

    /**
     * Whether a resolved path lies below the folder and its way there has no hidden segment.
     * The way is counted from the prefix (below()), not from the folder and one separator
     * more: at the root of the file system, which ends in a separator already, that would
     * cut off the first character, and '/.hidden/x' would go through as 'hidden/x'.
     *
     * @internal Public for its tests: a root like 'C:\' exists on Windows only
     *
     * @param string $root The folder, resolved
     * @param string $target A path, resolved
     * @param non-empty-string $separator
     */
    public static function visibleBelow(string $root, string $target, string $separator = DIRECTORY_SEPARATOR): bool
    {
        $base = self::below($root, $separator);
        if (!str_starts_with($target, $base)) {
            return false;
        }

        foreach (explode($separator, substr($target, strlen($base))) as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
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
     * system: not empty, no leading dot ('.', '..', hidden names), no separator, no control
     * character, no dot or space at its end (Windows drops both).
     */
    private static function isPlainSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment[0] !== '.'
            && strpbrk($segment, '/\\') === false
            && preg_match('/[\x00-\x1f\x7f]/', $segment) !== 1
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
