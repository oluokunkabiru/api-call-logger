# Changelog

All notable changes to this package are documented here. The project follows
[Semantic Versioning](https://semver.org): while the version is 0.x, minor releases
may contain breaking changes.

Versioning in this repository:

- **Patch** (0.1.x): bug fixes, docs, formatting. Safe to update.
- **Minor** (0.x.0): new features, and while in 0.x, breaking changes such as raised requirements.
- **Major** (1.0.0): the first release proven in a production application.

## [Unreleased]

## [0.1.1] - 2026-10-17

- Changelog documents the release history and the versioning rules.

## [0.1.0] - 2026-10-17

- **Breaking:** requires PHP 8.2+ and Laravel 12 or 13. Laravel 10 and 11 can no longer be
  installed (Composer blocks every release for unpatched security advisories).
- CI workflow: PHP 8.2–8.4 × Laravel 12–13, lowest dependencies, MySQL 8, PHPStan and Pint.

## [0.0.4] - 2026-10-17

- Code style applied with Pint. Formatting only.

## [0.0.3] - 2026-10-17

- Static analysis fixes (PHPStan level 6): typed retry-attempt tracking, model property
  docs, return types on the dashboard controller and middleware.

## [0.0.2] - 2026-10-17

- README, changelog and `.env.example` documenting every feature and switch.

## [0.0.1] - 2026-10-17

First complete package. Its `composer.json` declares Laravel 10–13, but only 12 and 13 can
actually be installed (see 0.1.0).

- Outbound logging with `Http::withApiLogging()`, `ApiCallLogger::http()` and Guzzle middleware.
- Optional automatic capture of every Http client call, with host → provider mapping.
- `api-call-logger.inbound` middleware for incoming webhooks.
- Manual `log()` / `measure()` for non-Http-client calls.
- Deep, separator-insensitive redaction of credentials and PII. String truncation.
- Tenant, user, related model, correlation id and meta on every record.
- Safe retry of logged calls. Retry is refused when the stored payload is lossy.
- Queued persistence, `ApiCallLogged` event, pruning command and `MassPrunable` model.
- Dashboard with filters, 24h stats, request/response viewer and retry.
- Value-pattern masking: Luhn-checked card numbers, JWTs, Bearer/Basic credentials, emails,
  phones, digit runs in URL paths and custom regexes, each with its own switch.
- Encryption at rest for payload columns (APP_KEY or a dedicated key). Old rows stay readable when it is toggled.
  Queued log jobs are encrypted.
- Configurable retry rules: blockers vs. confirmations (`force`), unsafe-method mode,
  required idempotency key, only-failed, max retries per record and a concurrency lock.
- Retry attempt numbers for `Http::retry()`.
- Idempotency key capture, reuse on retry and opt-in generation.
- Correlation id propagated to queued jobs via Context, adoptable from a request header or
  W3C `traceparent`, optionally forwarded to providers.
- `forget()` and `api-call-logger:forget` for data-subject erasure.

[Unreleased]: https://github.com/oluokunkabiru/api-call-logger/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/oluokunkabiru/api-call-logger/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/oluokunkabiru/api-call-logger/compare/v0.0.4...v0.1.0
[0.0.4]: https://github.com/oluokunkabiru/api-call-logger/compare/v0.0.3...v0.0.4
[0.0.3]: https://github.com/oluokunkabiru/api-call-logger/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/oluokunkabiru/api-call-logger/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/oluokunkabiru/api-call-logger/releases/tag/v0.0.1
