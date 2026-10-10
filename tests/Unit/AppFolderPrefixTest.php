<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\AppFolder;

/**
 * What lies below a web app folder, computed from the text of the paths — also at the root
 * of the file system ('/', 'C:\'), which ends in a separator already: '//' as the prefix
 * matched no file, and a way counted from the folder and one separator more would cut off
 * the first character of '/.hidden/x' and let the hidden folder through.
 */
class AppFolderPrefixTest extends TestCase
{
    /**
     * @return array<string, array{string, non-empty-string, string}>
     */
    public static function prefixes(): array
    {
        return [
            'root on Unix' => ['/', '/', '/'],
            'folder on Unix' => ['/srv/app', '/', '/srv/app/'],
            'folder with a separator at its end' => ['/srv/app/', '/', '/srv/app/'],
            'root of a drive' => ['C:\\', '\\', 'C:\\'],
            'folder on a drive' => ['C:\\site', '\\', 'C:\\site\\'],
        ];
    }

    /**
     * @param non-empty-string $separator
     */
    #[DataProvider('prefixes')]
    public function testFolderAsAPrefixEndsInOneSeparator(string $root, string $separator, string $prefix): void
    {
        $this->assertSame($prefix, AppFolder::below($root, $separator));
    }

    /**
     * @return array<string, array{string, string, non-empty-string, bool}>
     */
    public static function ways(): array
    {
        return [
            'file below the root' => ['/', '/srv/file.txt', '/', true],
            'hidden folder right below the root' => ['/', '/.hidden/x', '/', false],
            'hidden file right below the root' => ['/', '/.env', '/', false],
            'hidden folder deeper below the root' => ['/', '/srv/.git/config', '/', false],
            'file below a folder' => ['/srv/app', '/srv/app/pub/x', '/', true],
            'hidden folder right below a folder' => ['/srv/app', '/srv/app/.hidden/x', '/', false],
            'name that only begins like the folder' => ['/srv/app', '/srv/application/x', '/', false],
            'outside the folder' => ['/srv/app', '/etc/passwd', '/', false],
            'file below the root of a drive' => ['C:\\', 'C:\\site\\file.txt', '\\', true],
            'hidden folder right below the root of a drive' => ['C:\\', 'C:\\.hidden\\x', '\\', false],
            'hidden folder below a folder on a drive' => ['C:\\site', 'C:\\site\\.hidden\\x', '\\', false],
            'other drive' => ['C:\\', 'D:\\site\\file.txt', '\\', false],
        ];
    }

    /**
     * @param non-empty-string $separator
     */
    #[DataProvider('ways')]
    public function testWayBelowTheFolderHasNoHiddenSegment(string $root, string $target, string $separator, bool $visible): void
    {
        $this->assertSame($visible, AppFolder::visibleBelow($root, $target, $separator));
    }
}
