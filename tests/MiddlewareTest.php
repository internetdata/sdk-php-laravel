<?php

declare(strict_types=1);

namespace InternetData\Laravel\Tests;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InternetData\Client;
use InternetData\Laravel\InternetDataMiddleware;
use InternetData\Laravel\Selectors;
use InternetData\Middleware\Core;
use InternetData\Middleware\Lookup;
use InternetData\Middleware\Options as MwOptions;
use InternetData\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The middleware, driven with real Laravel requests, plus the shared corpus.
 *
 * A request from 127.0.0.1 is a bogon and is answered locally without a request.
 * Anything that needs a served answer therefore has to arrive wearing a public
 * address, through a selector.
 */
final class MiddlewareTest extends TestCase
{
    private const PUBLIC_IP = '146.70.116.130';

    /** @return array<string, mixed> */
    private static function corpus(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return $data['middleware'];
    }

    private static function toIdiom(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'toIdiom'], $value);
        }
        $out = [];
        foreach ($value as $key => $entry) {
            $out[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = self::toIdiom($entry);
        }
        return $out;
    }

    /** @param object|array<string, mixed> $body */
    private static function serving(object|array $body, int $status = 200): Stub
    {
        $ip = is_object($body) ? $body->ip : ($body['ip'] ?? self::PUBLIC_IP);
        return new Stub(Stub::lookups([$ip => ['status' => $status, 'body' => $body]]));
    }

    private static function client(Stub $stub): Client
    {
        return new Client(new Options(cache: false, retries: 0, httpClient: $stub->client));
    }

    private static function request(array $headers = [], string $ip = '127.0.0.1'): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::create('/', 'GET', server: $server);
    }

    private static function middleware(MwOptions $options, mixed $onBlocked = null): InternetDataMiddleware
    {
        return new InternetDataMiddleware(
            new Core($options, Selectors::default()),
            $onBlocked ?? [InternetDataMiddleware::class, 'refuse'],
        );
    }

    private static function pass(InternetDataMiddleware $middleware, Request $request): Response
    {
        return $middleware->handle($request, static fn (Request $r): Response => new Response('ok'));
    }

    public function testEnrichesTheRequestAndLeavesTheDecisionToTheApp(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $middleware = self::middleware(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
        ));
        $request = self::request();
        $response = self::pass($middleware, $request);

        self::assertSame(200, $response->getStatusCode());
        $lookup = InternetDataMiddleware::lookup($request);
        self::assertInstanceOf(Lookup::class, $lookup);
        self::assertSame('AT', $lookup->result?->location->country);
        self::assertSame(9009, $lookup->result?->asn->asn);
        self::assertSame(self::PUBLIC_IP, $lookup->ip);
    }

    public function testBlocksWhenTheConditionMatchesAndTheRouteNeverRuns(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $middleware = self::middleware(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['location' => ['country' => ['CU', 'IR', 'AT']]],
        ));
        $reached = false;
        $response = $middleware->handle(self::request(), function (Request $r) use (&$reached): Response {
            $reached = true;
            return new Response('ok');
        });
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($reached, 'the route answered a blocked request');
    }

    public function testOnBlockedReplacesTheRefusal(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $middleware = self::middleware(
            new MwOptions(
                client: self::client($stub),
                ipSelector: fn (Request $r): string => self::PUBLIC_IP,
                blockCondition: ['location' => ['country' => 'AT']],
            ),
            fn (Request $r, Lookup $l): Response => new JsonResponse(
                ['why' => $l->result->location->country],
                451
            ),
        );
        $response = self::pass($middleware, self::request());
        self::assertSame(451, $response->getStatusCode());
        self::assertSame('{"why":"AT"}', $response->getContent());
    }

    public function testAConditionReachesAnyMemberOfTheAnswer(): void
    {
        $mismatch = self::middleware(new MwOptions(
            client: self::client(self::serving(Stub::answer(self::PUBLIC_IP))),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['asn' => ['type' => 'isp']],
        ));
        self::assertSame(200, self::pass($mismatch, self::request())->getStatusCode());

        $match = self::middleware(new MwOptions(
            client: self::client(self::serving(Stub::answer(self::PUBLIC_IP))),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['asn' => ['type' => 'HOSTING']],
        ));
        self::assertSame(403, self::pass($match, self::request())->getStatusCode());
    }

    public function testSkipLeavesTheRequestUntouched(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $middleware = self::middleware(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['location' => ['country' => 'AT']],
            skip: fn (Request $r): bool => $r->path() === '/',
        ));
        $request = self::request();
        self::assertSame(200, self::pass($middleware, $request)->getStatusCode());
        self::assertNull(InternetDataMiddleware::lookup($request));
        self::assertSame([], $stub->calls);
    }

    public function testAFailingLookupLetsTheVisitorThrough(): void
    {
        $failing = self::client(self::serving(['ip' => self::PUBLIC_IP, 'error' => 'boom'], 500));
        $middleware = self::middleware(new MwOptions(
            client: $failing,
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['location' => ['country' => 'AT']],
        ));
        $request = self::request();
        self::assertSame(200, self::pass($middleware, $request)->getStatusCode());
        self::assertNotNull(InternetDataMiddleware::lookup($request)?->error);
    }

    // The test that matters. Every other assertion here would pass whether or not the
    // selector is right, because a direct connection has nothing to confuse.
    public function testAForgedXForwardedForIsIgnoredByDefault(): void
    {
        $stub = self::serving(Stub::answer(self::PUBLIC_IP));
        $middleware = self::middleware(new MwOptions(client: self::client($stub)));
        $request = self::request(['X-Forwarded-For' => self::PUBLIC_IP]);
        self::pass($middleware, $request);

        self::assertSame('127.0.0.1', InternetDataMiddleware::lookup($request)?->ip);
        self::assertSame([], $stub->calls, 'a bogon is answered locally, so nothing was asked');

        $explicit = self::serving(Stub::answer(self::PUBLIC_IP));
        $viaSelector = self::middleware(new MwOptions(
            client: self::client($explicit),
            ipSelector: Selectors::xff(),
        ));
        $forged = self::request(['X-Forwarded-For' => self::PUBLIC_IP]);
        self::pass($viaSelector, $forged);
        self::assertSame(self::PUBLIC_IP, InternetDataMiddleware::lookup($forged)?->ip);
    }

    public function testAHeaderSelectorReadsTheEdgeThatWritesIt(): void
    {
        $stub = self::serving(Stub::answer('146.70.116.131'));
        $middleware = self::middleware(new MwOptions(
            client: self::client($stub),
            ipSelector: Selectors::header('CF-Connecting-IP'),
        ));
        $request = self::request(['CF-Connecting-IP' => '146.70.116.131']);
        self::pass($middleware, $request);
        self::assertSame('146.70.116.131', InternetDataMiddleware::lookup($request)?->ip);
    }

    public function testCorpusConditions(): void
    {
        // Decoded with objects too, so a detail served as `{}` is not sent as `[]`.
        $wire = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            false,
            512,
            JSON_THROW_ON_ERROR
        )->middleware->conditions;
        foreach (self::corpus()['conditions'] as $i => $case) {
            $why = "{$case['name']}: {$case['why']}";
            $ip = $case['bogon'] ?? $case['body']['ip'];
            $stub = self::serving(isset($case['body']) ? $wire[$i]->body : ['ip' => $ip]);
            $warnings = [];
            $middleware = self::middleware(new MwOptions(
                client: self::client($stub),
                ipSelector: fn (Request $r) => $ip,
                blockCondition: self::toIdiom($case['condition']),
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ));
            $response = self::pass($middleware, self::request());
            self::assertSame($case['expect']['blocked'] ? 403 : 200, $response->getStatusCode(), $why);

            $reported = array_values(array_filter(
                $warnings,
                static fn (string $m): bool => str_contains($m, 'does not include')
            ));
            self::assertCount($case['expect']['missing'] === [] ? 0 : 1, $reported, $why);
        }
    }

    public function testCorpusRefusesAConditionThatConstrainsNothing(): void
    {
        foreach (self::corpus()['invalidConditions'] as $case) {
            $refused = false;
            try {
                self::middleware(new MwOptions(blockCondition: self::toIdiom($case['condition'])));
            } catch (\InvalidArgumentException $e) {
                $refused = str_contains($e->getMessage(), 'constrains nothing');
            }
            self::assertTrue($refused, "{$case['name']}: {$case['why']}");
        }
    }
}
