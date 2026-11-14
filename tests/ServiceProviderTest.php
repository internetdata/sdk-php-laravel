<?php

declare(strict_types=1);

namespace InternetData\Laravel\Tests;

use Illuminate\Http\Request;
use InternetData\Client;
use InternetData\Laravel\InternetDataMiddleware;
use InternetData\Laravel\InternetDataServiceProvider;
use InternetData\Options;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The middleware as an app gets it: from the service provider, configured through
 * `config/internetdata.php`. Every other test builds the core by hand, so a key the
 * provider dropped on its way to the core would pass them all.
 */
final class ServiceProviderTest extends TestCase
{
    private const PUBLIC_IP = '146.70.116.130';

    private Stub $stub;

    protected function getPackageProviders($app): array
    {
        return [InternetDataServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->stub = new Stub(Stub::lookups([
            self::PUBLIC_IP => ['status' => 200, 'body' => Stub::answer(self::PUBLIC_IP)],
        ]));
        $app['config']->set('internetdata.client', new Client(
            new Options(cache: false, retries: 0, httpClient: $this->stub->client),
        ));
        $app['config']->set('internetdata.ip_selector', static fn (Request $r): string => self::PUBLIC_IP);
        $app['config']->set(
            'internetdata.block_condition',
            ['location' => ['country' => ['CU', 'IR', 'AT']]],
        );
    }

    public function testAConfiguredConditionRefusesTheRequest(): void
    {
        $middleware = $this->app->make(InternetDataMiddleware::class);
        $reached = false;
        $response = $middleware->handle(Request::create('/'), function () use (&$reached): Response {
            $reached = true;
            return new Response('ok');
        });

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($reached, 'the route answered a blocked request');
        self::assertCount(1, $this->stub->calls);
    }
}
