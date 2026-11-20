# Changelog

What each release changed for you, newest first. Each line is a commit's summary, linked to its full description and diff.

## 1.0.3 - 2026-11-20

### Fixes

- Require internetdata 2.13.3: the spec re-pinned to 2026.11.20 ([`e151ebe`](https://github.com/internetdata/sdk-php-laravel/commit/e151ebe46f7ee482beb757ea1e21da3fcb63fb0c))

## 1.0.2 - 2026-11-19

### Fixes

- Require internetdata 2.13.2: a reduced answer is cached a minute at most ([`4db4487`](https://github.com/internetdata/sdk-php-laravel/commit/4db4487eded29521f8e2e797b886e626f23091a2))

## 1.0.1 - 2026-11-19

### Fixes

- Require internetdata 2.13.1: cap_reached on a paid answer past its overage cap ([`79555e6`](https://github.com/internetdata/sdk-php-laravel/commit/79555e6ceb77307a54ae88a76f1ebc0fcde45e2d))

## 1.0.0 - 2026-11-14

### Features

- Attach the visitor's InternetData record to every request, read with `InternetDataMiddleware::lookup($request)`: the location, the network and the company behind the address, and the anonymity and hosting flags your plan serves ([`2e346ba`](https://github.com/internetdata/sdk-php-laravel/commit/2e346badfe58b23045ea814dd65bfd6f967440c7))
- Refuse a request with a `block_condition` in `config/internetdata.php`, written in the shape of a result, by country, network type, anonymity or any other member, with 403 or an `on_blocked` of your own ([`2e346ba`](https://github.com/internetdata/sdk-php-laravel/commit/2e346badfe58b23045ea814dd65bfd6f967440c7))
- Choose where the client address comes from: `$request->ip()` by default, which honours `TrustProxies`, `Selectors::xff()`, `Selectors::header()` for a header your edge writes, or a callable of your own ([`2e346ba`](https://github.com/internetdata/sdk-php-laravel/commit/2e346badfe58b23045ea814dd65bfd6f967440c7))
- Keep answers in your app's default cache store for an hour, so a returning visitor costs nothing under PHP-FPM; `cache_store` names another store or turns it off ([`2e346ba`](https://github.com/internetdata/sdk-php-laravel/commit/2e346badfe58b23045ea814dd65bfd6f967440c7))
- Fail open on any lookup failure unless `fail_closed` is set, with a 2.5 s bound and no retry by default ([`2e346ba`](https://github.com/internetdata/sdk-php-laravel/commit/2e346badfe58b23045ea814dd65bfd6f967440c7))
