# [<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="28"/>](https://internetdata.io/) InternetData Laravel Middleware

[![Packagist](https://img.shields.io/packagist/v/internetdata/laravel.svg)](https://packagist.org/packages/internetdata/laravel)
[![license](https://img.shields.io/packagist/l/internetdata/laravel.svg)](LICENSE)

The official [Laravel](https://laravel.com) middleware for the [InternetData](https://internetdata.io) API.

It looks up the visitor behind each request, where they are, which network they come from and whether it's a VPN, a proxy, Tor or a hosting server, and hands the answer to your code. Blocking is opt-in.

## Getting Started

```bash
composer require internetdata/laravel
```

Requires PHP 8.2 or newer and Laravel 10, 11, 12 or 13.

Without an API key a lookup is on Unlimited, the free plan: the country, the network and the company behind the address, Tor, relay, anycast, carrier and satellite space and the service flags, with no daily or monthly cap. Every lookup your app makes comes from your server's one address, though, so a busy site will see bursts refused. A paid plan answers every field, VPN, the proxy flags, hosting, CDN and the city among them; create a key in the [console](https://app.internetdata.io). A free account tries every field.

The service provider is auto-discovered. Publish the config and register the middleware:

```bash
php artisan vendor:publish --tag=internetdata-config
```

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\InternetData\Laravel\InternetDataMiddleware::class);
})
```

Set `INTERNETDATA_API_KEY` in your `.env`.

```php
use InternetData\Laravel\InternetDataMiddleware;

Route::get("/", function (Request $request) {
    $result = InternetDataMiddleware::lookup($request)?->result;
    return "Hello, visitor from " . ($result?->location->countryName ?? "somewhere");
});
```

By default nothing is blocked. Every request gets an answer and your own code decides what it means: a currency to show, a sign-up to review, a page to keep from some countries.

## Blocking

Set a `block_condition` and a matching request is answered with `403` and never reaches your code.

```php
// config/internetdata.php
'block_condition' => ['location' => ['country' => ['CU', 'IR', 'KP']]],
```

A condition is written in the shape of a `Result`, and only the members you name are considered, so it can refuse on anything in the answer. The country works on every plan, and so do the first two of these:

```php
['asn' => ['type' => 'hosting']]                          // the network's type
[['isAnycast' => true], ['isSatellite' => true]]          // anycast and satellite space
[['isHosting' => true], ['isCdn' => true]]                // traffic from servers, not people
[['isVpn' => true], ['isTor' => true], ['isResproxy' => true]]  // anonymity: a list is OR
['isVpn' => true, 'vpn' => ['provider' => 'nordvpn']]     // one provider
['resproxy' => ['hits' => ['gte' => 5]]]                  // a numeric threshold
```

Values are matched by equality, strings without regard to case. A LIST means any-of. A map of `gte`/`gt`/`lte`/`lt` compares numbers, and every bound you give must hold, so two of them are a range. Members set to `false` or `null` are ignored, so a condition states the signals you act on; one that constrains nothing would match every request, and is refused when the middleware is built rather than silently blocking all your traffic.

## Where the client address comes from

This is the setting that decides whether any of the above works, and it is the one thing only you can get right.

By default the middleware uses `$request->ip()`, which honours Laravel's own `TrustProxies` middleware. That is the right fix behind a load balancer: set `TrustProxies` to the proxies actually in front of you and `ip()` walks the chain for you. Without it, and behind one, every visitor looks like the load balancer, and that is the address that gets looked up: every visitor gets its country and network, and if it is a hosting address, a hosting rule blocks everyone. A load balancer in your own network usually has a private address, which the middleware answers locally without a lookup, as an address with no country, and logs a warning.

For an edge that writes the address into its own header, name the header:

```php
// config/internetdata.php
'ip_selector' => \InternetData\Laravel\Selectors::header('CF-Connecting-IP'),
```

`\InternetData\Laravel\Selectors::xff()` reads `X-Forwarded-For` directly. Be aware that the left-most entry is whatever the caller sent, because proxies append to that header, so it is only trustworthy when an edge you control overwrites it. If you know how many proxies sit in front, count from the right instead: `Selectors::xff(1)` is the address your nearest proxy saw.

Anything else, pass your own callable. It receives the request and returns an address.

If the address resolves to a private one, the middleware says so once each time the application boots, which under PHP-FPM is every request. That is expected locally and is the signal to fix your configuration anywhere else.

## When a lookup fails

The request is let through, and the reason is on the answer's `error`. Our outage should not become yours, so a network failure, a rate limit or a rejected key all fail open. Set `fail_closed` to block instead. Private addresses are answered locally and never fail, so this will not lock you out in development.

## Cost and latency

Answers are cached for an hour in your app's default cache store, keyed by a hash of your API key and the visitor's address, so a returning visitor costs nothing even under PHP-FPM, where the application is built afresh for every request. Private addresses never leave the process. A lookup is one request to our API, bounded at 2.5 seconds by default and not retried: on a request path, failing open quickly beats holding a visitor while we try again.

Name another store from `config/cache.php` with `cache_store`, or set it to `false` to keep answers in the client alone, which under PHP-FPM lasts one request.

Beyond a few million distinct visitors a day, stop calling the API per request: look addresses up locally in [our databases](https://internetdata.io/databases) instead.

## Absent is not false

Unlimited, with a key or without one, leaves out `isVpn`, `isHosting`, `isCdn` and the four proxy flags, the detail behind them, and the paid members inside `location`, `asn`, `company` and `abuse`, such as `location->city`. A property your plan does not include is `null`, which means "not in your plan" rather than "checked, and no".

```php
$lookup->result->isVpn ?? false   // when you only want the flag
```

A `block_condition` naming a member your plan does not serve, `location.city` on Unlimited included, can never match, so the middleware warns, once each time the application boots, instead of failing silently. Set `on_missing_field` to `throw` to make it an error.

## Other Libraries

There are official InternetData client libraries available for many languages including PHP, Python, Go, Java, and Ruby. See our GitHub at https://github.com/internetdata for more.

## About InternetData

Geolocation, anonymity, ownership and network data for IP addresses, AS numbers and domains, as an API and as databases you download and query yourself.

[<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="64"/>](https://internetdata.io/)

## License

This project is licensed under the [MIT License](LICENSE).
