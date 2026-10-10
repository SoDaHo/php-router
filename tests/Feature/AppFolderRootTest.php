<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\AppFolder;

/**
 * A web app folder may be the root of the file system — the constructor takes it. Its files
 * were compared against the prefix '//' and none was ever served.
 */
class AppFolderRootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/router_app_root_' . uniqid();
        mkdir($dir);
        $this->dir = (string) realpath($dir);
        file_put_contents($this->dir . '/file.txt', 'from the root');
    }

    protected function tearDown(): void
    {
        unlink($this->dir . '/file.txt');
        rmdir($this->dir);
    }

    public function testFileBelowTheRootOfTheFileSystemIsServed(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('The root of the file system is "/" here only on Unix-like systems');
        }

        // The resolved path of the file, as a request path below the root
        $path = $this->dir . '/file.txt';
        $response = new AppFolder('/', '/')->serve(new ServerRequest('GET', $path), $path);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from the root', (string) $response->getBody());

        $handle = AppFolder::openWithin('/', $path);
        $this->assertIsResource($handle);
        fclose($handle);
    }
}
