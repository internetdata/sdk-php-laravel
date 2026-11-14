<?php

declare(strict_types=1);

namespace InternetData\Laravel\Tests;

use Illuminate\Http\Request;
use InternetData\Client;
use InternetData\Laravel\InternetDataMiddleware;
use InternetData\Laravel\InternetDataServiceProvider;
use InternetData\Middleware\Lookup;
use InternetData\Options;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Under PHP-FPM the application, and the client in it, is built for every request, so
 * only the app's cache store carries an answer from one request to the next. Another
 * client sharing the store writes the answer, and the provider's own client reaches
 * only an origin that refuses it: it can answer from the store alone.
 */
final class CacheStoreTest extends TestCase
{
    private const PUBLIC_IP = '146.70.116.130';

    private const REFUSED = 'http://127.0.0.1:9';

    protected function getPackageProviders($app): array
    {
        return [InternetDataServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('internetdata.api_key', 'k');
        $app['config']->set('internetdata.base_url', self::REFUSED);
        $app['config']->set('internetdata.ip_selector', static fn (Request $r): string => self::PUBLIC_IP);
    }

    public function testTheDefaultStoreCarriesAnAnswerBetweenRequests(): void
    {
        $stub = $this->answerOnce();

        $lookup = $this->visit();

        self::assertSame('AT', $lookup?->result?->location->country);
        self::assertCount(1, $stub->calls);
    }

    public function testCacheStoreFalseKeepsAnswersInTheClient(): void
    {
        $this->answerOnce();
        $this->app['config']->set('internetdata.cache_store', false);

        $lookup = $this->visit();

        self::assertNull($lookup?->result);
        self::assertNotNull($lookup?->error);
    }

    private function answerOnce(): Stub
    {
        $stub = new Stub(Stub::lookups([
            self::PUBLIC_IP => ['status' => 200, 'body' => Stub::answer(self::PUBLIC_IP)],
        ]));
        (new Client(new Options(
            apiKey: 'k',
            baseUrl: self::REFUSED,
            cacheStore: $this->app['cache']->store(),
            httpClient: $stub->client,
        )))->lookup(self::PUBLIC_IP);
        return $stub;
    }

    private function visit(): ?Lookup
    {
        $request = Request::create('/');
        $this->app->make(InternetDataMiddleware::class)->handle($request, static fn (): Response => new Response('ok'));
        return InternetDataMiddleware::lookup($request);
    }
}
