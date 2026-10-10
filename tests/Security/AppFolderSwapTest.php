<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Security;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\AppFolder;

/**
 * A file of a web app folder is resolved (realpath) and then opened. A writer of the folder
 * who put a link to a file elsewhere in its place in between had that file sent. The file
 * is opened first now and checked at the handle (AppFolder::openWithin()).
 */
class AppFolderSwapTest extends TestCase
{
    private string $base;
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/router_app_swap_' . uniqid();
        mkdir($base . '/app/assets', 0o777, true);
        mkdir($base . '/outside', 0o777, true);
        $this->base = (string) realpath($base);
        $this->root = $this->base . '/app';

        file_put_contents($this->root . '/file.txt', 'INSIDE');
        file_put_contents($this->root . '/index.html', '<p>app</p>');
        file_put_contents($this->base . '/outside/secret.txt', 'SECRET');
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->root . '/*') ?: [], ...glob($this->root . '/assets/*') ?: [], ...glob($this->base . '/outside/*') ?: []] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        foreach ([$this->root . '/assets', $this->root, $this->base . '/outside', $this->base] as $dir) {
            if (is_link($dir)) {
                unlink($dir);
            } elseif (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testFileThatIsStillTheResolvedOneIsOpened(): void
    {
        $handle = AppFolder::openWithin($this->root, $this->root . '/file.txt');

        $this->assertIsResource($handle);
        $this->assertSame('INSIDE', stream_get_contents($handle));
        fclose($handle);
    }

    /**
     * What a swap between resolving and opening leaves behind: under the resolved name
     * stands a link now — to the file itself, or to a directory on the way
     */
    public function testLinkPutInPlaceOfTheResolvedFileIsNotOpened(): void
    {
        symlink($this->base . '/outside/secret.txt', $this->root . '/swapped.txt');
        $this->assertNull(AppFolder::openWithin($this->root, $this->root . '/swapped.txt'));

        rmdir($this->root . '/assets');
        symlink($this->base . '/outside', $this->root . '/assets');
        $this->assertNull(AppFolder::openWithin($this->root, $this->root . '/assets/secret.txt'));
    }

    public function testFileOutsideTheFolderIsNotOpened(): void
    {
        $this->assertNull(AppFolder::openWithin($this->root, $this->base . '/outside/secret.txt'));
        $this->assertNull(AppFolder::openWithin($this->root, $this->root . '/missing.txt'));
    }

    public function testHardLinkIsTheFileItNames(): void
    {
        // Documented: nothing tells a hard link apart from the file itself
        if (!@link($this->base . '/outside/secret.txt', $this->root . '/hard.txt')) {
            $this->markTestSkipped('No hard links here');
        }

        $handle = AppFolder::openWithin($this->root, $this->root . '/hard.txt');
        $this->assertIsResource($handle);
        fclose($handle);
    }

    /**
     * The race itself: a second process swaps the file for a link to a file outside the
     * folder and back, as fast as it can, while the folder serves the file. Whatever the
     * timing, the content outside never goes out — the file that was checked, or nothing.
     */
    public function testSwappingTheFileForALinkNeverSendsWhatLiesOutside(): void
    {
        $swapper = (string) json_encode([
            'file' => $this->root . '/file.txt',
            'secret' => $this->base . '/outside/secret.txt',
            'stop' => $this->base . '/stop',
        ]);
        $process = proc_open(
            [PHP_BINARY, '-r', self::SWAPPER, $swapper],
            // Nothing to read from it: what it might print must not fill a pipe and stop it
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            // The swapper is a process of its own: wait until it has put the link in place
            // once (a slow machine starts it late), so that the requests run while it swaps
            $deadline = microtime(true) + 20;
            while (!file_exists($this->root . '/file.txt.inside') && !is_link($this->root . '/file.txt') && microtime(true) < $deadline) {
                usleep(1000);
            }

            $app = new AppFolder('/', $this->root);
            $seen = ['INSIDE' => 0, 'SECRET' => 0, 'none' => 0];

            for ($i = 0; $i < 3000; $i++) {
                $response = $app->serve(new ServerRequest('GET', '/file.txt'), '/file.txt');
                $body = $response === null ? 'none' : (string) $response->getBody();
                $seen[$body] = ($seen[$body] ?? 0) + 1;
            }
        } finally {
            touch($this->base . '/stop');
            proc_close($process);
            @unlink($this->base . '/stop');
        }

        $this->assertSame(0, $seen['SECRET'], 'what lies outside the folder went out: ' . json_encode($seen));
        $this->assertGreaterThan(0, $seen['INSIDE'], 'the file itself never went out: ' . json_encode($seen));
    }

    /** Swaps file.txt for a link to the secret and back (rename() is atomic) until told to stop */
    private const SWAPPER = <<<'PHP'
        $paths = json_decode($argv[1], true);
        $file = $paths['file'];
        $inside = $file . '.inside';
        $link = $file . '.link';
        for ($i = 0; !file_exists($paths['stop']) && $i < 2000000; $i++) {
            @file_put_contents($inside, 'INSIDE');
            @symlink($paths['secret'], $link);
            @rename($link, $file);
            @rename($inside, $file);
        }
        @unlink($inside);
        @unlink($link);
        PHP;
}
