<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Feature;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Middleware\RouteHandler;
use Sodaho\Router\Response;
use Sodaho\Router\Router;

/**
 * A route [ExpectedController::class, 'show'] ran whatever the container returned under that
 * name, as long as it had a method 'show': a wrong alias or factory answered with another
 * object's handler — a consent or token handler of the wrong kind — without a word. The
 * entry has to be an object of the class now (or of one below it, or of one that implements
 * the interface named), as for middleware.
 */
class ControllerFromContainerTest extends TestCase
{
    private const REFUSED = 'The container entry for a controller is no object of the class the route names: register the controller itself under its class name';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * A container that has exactly one entry.
     */
    private function containerWith(string $id, mixed $entry): ContainerInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn (string $asked): bool => $asked === $id);
        $container->method('get')->willReturn($entry);

        return $container;
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function entriesOfAnotherKind(): array
    {
        return [
            'an object of another class with a method of the same name' => [new WrongController(), WrongController::class],
            'a factory' => [static fn (): ExpectedController => new ExpectedController(), 'Closure'],
            'no object' => ['controller', 'string'],
        ];
    }

    #[DataProvider('entriesOfAnotherKind')]
    public function testEntryOfAnotherKindIsRefused(mixed $entry, string $type): void
    {
        $handler = new RouteHandler([ExpectedController::class, 'show'], $this->containerWith(ExpectedController::class, $entry));

        try {
            $handler->handle(new ServerRequest('GET', '/x'));
            $this->fail('The entry was called');
        } catch (RouterException $e) {
            $this->assertSame(self::REFUSED, $e->getMessage());
            $this->assertSame(ExpectedController::class . ': ' . $type, $e->getDebugMessage());
        }
    }

    public function testRequestWhoseControllerIsOfAnotherKindIsA500ThatIsReported(): void
    {
        $file = sys_get_temp_dir() . '/router_controller_' . uniqid() . '.php';
        file_put_contents($file, '<?php return function ($r) { $r->get("/x", [' . var_export(ExpectedController::class, true) . ', "show"]); };');
        $this->files[] = $file;

        $reported = [];
        $router = Router::create()
            ->loadRoutes($file)
            ->setContainer($this->containerWith(ExpectedController::class, new WrongController()))
            ->on('error', function (array $data) use (&$reported): void {
                $reported[] = $data['exception'];
            });

        $response = $router->handle(new ServerRequest('GET', '/x'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('WRONG', (string) $response->getBody());
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]);
        $this->assertSame(self::REFUSED, $reported[0]->getMessage());
    }

    /**
     * @return array<string, array{0: string, 1: object, 2: string}>
     */
    public static function entriesOfTheClass(): array
    {
        return [
            'the class itself' => [ExpectedController::class, new ExpectedController(), 'EXPECTED'],
            'a class below it' => [ExpectedController::class, new MoreExpectedController(), 'MORE'],
            'a class that implements the interface named' => [ShowsSomething::class, new ExpectedController(), 'EXPECTED'],
        ];
    }

    #[DataProvider('entriesOfTheClass')]
    public function testEntryOfTheClassIsCalled(string $class, object $entry, string $body): void
    {
        $handler = new RouteHandler([$class, 'show'], $this->containerWith($class, $entry));

        $this->assertSame($body, (string) $handler->handle(new ServerRequest('GET', '/x'))->getBody());
    }
}

/** What a route of these tests names. */
interface ShowsSomething
{
    public function show(): ResponseInterface;
}

/** The controller the route names. */
class ExpectedController implements ShowsSomething
{
    public function show(): ResponseInterface
    {
        return Response::text('EXPECTED');
    }
}

/** A controller below the one the route names. */
final class MoreExpectedController extends ExpectedController
{
    public function show(): ResponseInterface
    {
        return Response::text('MORE');
    }
}

/** Another controller with a method of the same name — what a wrong alias returns. */
final class WrongController
{
    public function show(): ResponseInterface
    {
        return Response::text('WRONG');
    }
}
