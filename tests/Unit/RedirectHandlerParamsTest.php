<?php

declare(strict_types=1);

namespace Sodaho\Router\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Router\Exception\RouterException;
use Sodaho\Router\Middleware\RedirectHandler;
use Sodaho\Router\Router;

/**
 * A RedirectHandler built by hand checks what it is given where the redirect goes out, as
 * redirect() checks it where the route is written: `new RedirectHandler('/go/{id}')` without
 * a value for {id} sent 'Location: /go/{id}', and a value that is no scalar became 'Array'
 * with a warning. The route parameters are an array of scalar values, every placeholder of
 * the target gets one — or the redirect is not sent.
 */
class RedirectHandlerParamsTest extends TestCase
{
    private const NO_VALUE = 'Redirect not sent: a placeholder of the target has no value';
    private const NO_ARRAY = 'Redirect not sent: the route parameters (_route_params) are no array of values';
    private const NO_SCALAR = 'Redirect not sent: a route parameter is no scalar value';

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}>
     */
    public static function parametersThatDoNotFillTheTarget(): array
    {
        return [
            'no parameters at all' => [null, self::NO_VALUE, '/go/{id}'],
            'no parameters' => [[], self::NO_VALUE, '/go/{id}'],
            'a parameter of another name' => [['other' => 'x'], self::NO_VALUE, '/go/{id}'],
            'parameters that are no array' => ['id=5', self::NO_ARRAY, 'string'],
            'parameters that are an object' => [new \ArrayObject(['id' => 5]), self::NO_ARRAY, 'ArrayObject'],
            'an array as a value' => [['id' => ['5']], self::NO_SCALAR, 'id: array'],
            'null as a value' => [['id' => null], self::NO_SCALAR, 'id: null'],
            'an object as a value' => [['id' => new \stdClass()], self::NO_SCALAR, 'id: stdClass'],
            'a number that is none' => [['id' => NAN], self::NO_SCALAR, 'id: float'],
        ];
    }

    #[DataProvider('parametersThatDoNotFillTheTarget')]
    public function testRedirectIsNotSentWithoutAScalarValueForEveryPlaceholder(mixed $params, string $message, string $debug): void
    {
        $request = new ServerRequest('GET', '/old');
        if ($params !== null) {
            $request = $request->withAttribute('_route_params', $params);
        }

        try {
            new RedirectHandler('/go/{id}')->handle($request);
            $this->fail('The redirect was sent');
        } catch (RouterException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($debug, $e->getDebugMessage());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function scalarValues(): array
    {
        return [
            'a string' => [['id' => 'a b'], '/go/a%20b'],
            'an integer' => [['id' => 5], '/go/5'],
            'a float' => [['id' => 1.5], '/go/1.5'],
            'true' => [['id' => true], '/go/1'],
            'a value more than the target takes' => [['id' => '5', 'other' => 'x'], '/go/5'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('scalarValues')]
    public function testScalarValueForEveryPlaceholderIsSent(array $params, string $location): void
    {
        $response = new RedirectHandler('/go/{id}')->handle(new ServerRequest('GET', '/old')->withAttribute('_route_params', $params));

        $this->assertSame($location, $response->getHeaderLine('Location'));
    }

    public function testTargetWithoutPlaceholdersNeedsNoValues(): void
    {
        $response = new RedirectHandler('/new')->handle(new ServerRequest('GET', '/old'));

        $this->assertSame('/new', $response->getHeaderLine('Location'));
    }

    public function testRouteOfAHandlerWithAPlaceholderItHasNoValueForIsA500ThatIsReported(): void
    {
        $file = sys_get_temp_dir() . '/router_redirect_params_' . uniqid() . '.php';
        file_put_contents($file, '<?php return function ($r) { $r->get("/old", new Sodaho\Router\Middleware\RedirectHandler("/go/{id}")); };');

        try {
            $reported = [];
            $response = Router::create()
                ->loadRoutes($file)
                ->on('error', function (array $data) use (&$reported): void {
                    $reported[] = $data['exception'];
                })
                ->handle(new ServerRequest('GET', '/old'));
        } finally {
            unlink($file);
        }

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Location'));
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(RouterException::class, $reported[0]);
        $this->assertSame(self::NO_VALUE, $reported[0]->getMessage());
    }
}
