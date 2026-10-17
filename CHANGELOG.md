# Changelog

## 1.0.0 - 2026-10-16

- Initial release.
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
