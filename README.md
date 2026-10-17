# API Call Logger

Provider-agnostic Laravel logger for **third-party API calls** and **incoming webhooks**.
Every call is stored with its request, response, headers, status, latency and outcome, with
credentials and PII **redacted before they touch the database**. It also gives you retries,
pruning and a built-in dashboard.

It replaces the hand-rolled `ThirdPartyApiLogger` service you would otherwise copy into
every project.

- **Zero-boilerplate Http client logging**: `Http::withApiLogging('vtpass', 'buy-airtime')->post(...)`
- **Automatic capture** (optional) of every `Http::` call, mapped to providers by host
- **Inbound webhook logging** middleware, provider read from the URL
- **Manual logging** for SOAP, cURL and SDK calls: `ApiCallLogger::log(...)` / `ApiCallLogger::measure(...)`
- **Deep redaction**: credentials always, PII toggleable, matched case- and separator-insensitively
- **Value-pattern masking**: card numbers (Luhn-checked), JWTs, Bearer/Basic credentials, emails, phones and IDs in URL paths
- **Encryption at rest** for payload columns, with APP_KEY or a dedicated key
- **Tenant, user, related model, correlation id and meta** stored on every record
- **Correlation across queued jobs** (Laravel Context), adoptable from `X-Request-Id` or W3C `traceparent`
- **Retry attempt numbers** for `Http::retry()` and **idempotency keys** stored, reused and optionally generated
- **Rule-based retry** you configure: blockers, confirmations, limits and a lock against double replays
- **Data-subject erasure** (`api-call-logger:forget`), **queueing**, **pruning** and an `ApiCallLogged` event
- **Dashboard** at `/api-call-logs` with filters, a 24h overview, request/response viewer and a Retry button
- Never breaks your call: logging failures are swallowed and reported to the app log

## Requirements

- PHP 8.2+
- Laravel 12 or 13

Laravel 10 and 11 are end-of-life. Composer now blocks every release of them because of
unpatched security advisories, so they are not supported.

## Installation

```bash
composer require oluokunkabiru/api-call-logger
php artisan migrate
```

The service provider and `ApiCallLogger` facade are auto-discovered. Optionally publish the config:

```bash
php artisan vendor:publish --tag=api-call-logger-config
php artisan vendor:publish --tag=api-call-logger-migrations   # to customise the table (then set run_migrations => false)
php artisan vendor:publish --tag=api-call-logger-views        # to customise the dashboard
```

### Environment variables

Every option can be set from `.env` without publishing the config. See [`.env.example`](.env.example)
for the full list with defaults. The most common ones:

```env
API_CALL_LOGGER_ENABLED=true
API_CALL_LOGGER_CAPTURE_HTTP_CLIENT=false       # log every Http:: call automatically
API_CALL_LOGGER_CAPTURE_ONLY_HOSTS=*.vtpass.com,api.paystack.co
API_CALL_LOGGER_REDACT_SENSITIVE=true           # false keeps PII for debugging; credentials always redacted
API_CALL_LOGGER_REDACT_KEYS=merchant_key,signature
API_CALL_LOGGER_QUEUE=false
API_CALL_LOGGER_PRUNE_DAYS=30                   # null keeps records forever
API_CALL_LOGGER_DASHBOARD_ENABLED=true
API_CALL_LOGGER_ROUTE_PREFIX=api-call-logs
API_CALL_LOGGER_DASHBOARD_MIDDLEWARE=web,auth
API_CALL_LOGGER_ENCRYPT=false                   # encrypt bodies/headers at rest
API_CALL_LOGGER_RETRY_UNSAFE=confirm            # allow | confirm | deny for POST/PUT/PATCH/DELETE retries
```

Every feature that changes what is stored or sent has its own switch, so each application decides.
Nothing extra is sent to providers unless you switch it on (correlation header, idempotency key).

Comma-separated values are used for lists (hosts, extra redaction keys, dashboard middleware).

For local development of this package, add a path repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../../../koadit/api-call-logger" }
]
```

## Usage

### 1. Outbound calls through Laravel's Http client (recommended)

```php
use Illuminate\Support\Facades\Http;

$response = Http::withApiLogging('vtpass', 'buy-airtime')
    ->withHeaders(['api-key' => $key, 'secret-key' => $secret])
    ->post("{$baseUrl}/pay", $payload);
```

The request is timed and logged with its headers, body, status and response. Your code
gets the normal `Response` back. Connection failures are logged too, and the exception is
still thrown as usual.

Options (third argument):

```php
Http::withApiLogging('gobeller', 'create-wallet', [
    'loggable' => $transaction,                              // link the log to a local model
    'tenant'   => $institution->code,                        // override the tenant for this call
    'meta'     => ['wallet_type' => 'savings'],              // anything extra
    'success'  => fn ($response) => (bool) $response->json('status'), // providers that return 200 + status:false
])->post(...);
```

`ApiCallLogger::http('vtpass', 'buy-airtime')` does the same thing as `Http::withApiLogging(...)`.
For a custom Guzzle client, push `ApiCallLogger::middleware('vtpass')` onto its handler stack.

### 2. Automatic capture of every Http client call

```env
API_CALL_LOGGER_CAPTURE_HTTP_CLIENT=true
```

```php
// config/api-call-logger.php
'capture' => [
    'http_client' => env('API_CALL_LOGGER_CAPTURE_HTTP_CLIENT', false),
    'only_hosts' => [],                 // e.g. ['*.vtpass.com', 'api.paystack.co']
    'except_hosts' => ['*.internal'],
],
'providers' => [
    'paystack' => ['hosts' => ['api.paystack.co']],
    'vtpass'   => ['hosts' => ['vtpass.com', 'sandbox.vtpass.com']],
],
```

Hosts that don't match a provider are logged under their hostname. A call made with
`withApiLogging()` is only logged once, even when capture is enabled.

### 3. Inbound webhooks

```php
// The provider comes from the segment after "webhooks": /api/webhooks/flutterwave → flutterwave
Route::prefix('webhooks')->middleware('api-call-logger.inbound')->group(function () {
    Route::post('flutterwave', FlutterwaveWebhookController::class);
    Route::post('fyatu', FyatuWebhookController::class);
});

// Or give the provider (and context) explicitly
Route::post('paystack/callback', ...)->middleware('api-call-logger.inbound:paystack,charge-success');
```

Inbound records have `direction = inbound`, the caller's IP in `meta.ip`, and your app's
response as `response_body`. Uploaded files are stored as name, MIME type and size only.

### 4. Anything else (SOAP, raw cURL, vendor SDKs)

```php
use Oluokunkabiru\ApiCallLogger\Facades\ApiCallLogger;

ApiCallLogger::log(
    provider: 'gobeller',
    method: 'POST',
    url: $this->baseUrl . $endpoint,
    request: $payload,                 // array or raw string (JSON / form decoded automatically)
    response: $responseBody,
    status: $statusCode,               // null for connection failures
    success: $success,                 // defaults to 2xx
    durationMs: $durationMs,
    context: 'self-service',
    requestHeaders: $headers,
    responseHeaders: $responseHeaders,
    loggable: $transaction,
);

// Or let the package time it for you:
$result = ApiCallLogger::measure('bank-soap', 'POST', $wsdl, fn () => $client->Transfer($args), request: $args);
```

### Request-scoped context

Set these once per request (in a middleware, for example). They apply to every call logged afterwards:

```php
ApiCallLogger::withTenant($request->header('X-Institution-Code'));
ApiCallLogger::withMeta(['channel' => 'mobile']);

// Or resolve them lazily, in a service provider:
ApiCallLogger::resolveTenantUsing(fn () => request()->attributes->get('institution')?->code);
ApiCallLogger::resolveUserUsing(fn () => auth('admin')->id());   // default: auth()->id()
```

All calls logged during one request share a `correlation_id`. The dashboard lists them
together, so you can follow a whole flow (e.g. *verify BVN → create wallet → fund*).

| Setting | Default | Effect |
| --- | --- | --- |
| `correlation.propagate_to_jobs` | `true` | Queued jobs dispatched by the request keep the same id (Laravel 11+ Context) |
| `correlation.share_with_logs` | `false` | The id also appears on every `Log::` line |
| `correlation.request_header` | `null` | Adopt the caller's id, e.g. `X-Request-Id`, or `traceparent` to reuse the OpenTelemetry trace id |
| `correlation.forward_header` | `null` | Send the id to providers, e.g. `X-Correlation-ID` |

### Retry attempts and idempotency keys

- Each attempt made by `Http::withApiLogging(...)->retry(3)` is logged with its number: `attempt` 1, 2, 3.
- An `Idempotency-Key` (or `X-Idempotency-Key`) header you send is stored in `idempotency_key` and re-sent on retry.
- With `API_CALL_LOGGER_AUTO_IDEMPOTENCY_KEY=true`, POST/PUT/PATCH/DELETE calls that have no key get one.
  All retry attempts of a call reuse the same key. Only enable it for providers that support idempotency keys.

## Redaction

```php
'redaction' => [
    'enabled' => env('API_CALL_LOGGER_REDACT_SENSITIVE', true), // false = keep PII for debugging
    'replacement' => '[REDACTED]',
    'always' => ['password', 'secret_key', 'authorization', 'token', 'pin', 'cvv', 'card_number', ...],
    'sensitive' => ['bvn', 'nin', 'document_id_no', 'image_encoding', ...],
    'max_string_length' => 500,  // longer strings become "<truncated N bytes>"
    'max_depth' => 10,
],
```

- Keys match at any depth of bodies, query strings and headers.
- Matching ignores case and separators, so `Secret-Key`, `secret_key` and `secretKey` are all caught.
- `always` keys (credentials) are redacted even when `enabled` is false.
- Multipart bodies are never read into memory. Only their size is stored.

### Value patterns

Key names miss secrets stored under unexpected keys, inside free text, or in the URL itself.
Value patterns catch them by what they look like. Each one has its own switch:

```php
'patterns' => [
    'card_numbers' => true,          // Luhn-valid 13–19 digits → 411111******1111 (PCI first 6 / last 4)
    'jwt' => true,                   // → [REDACTED:jwt]
    'auth_schemes' => true,          // "Bearer abc…" / "Basic abc…" inside any string
    'emails' => false,               // → jo***@gmail.com
    'phones' => false,               // → +*********4567
    'url_digits_min_length' => 10,   // /kyc/bvn/22222222222 → /kyc/bvn/*******2222 (null = off)
    'custom' => ['/sk_(live|test)_[A-Za-z0-9]+/' => '[REDACTED:paystack-key]'],
],
```

Card masking checks the Luhn checksum first, so order references and amounts are left alone.

## Encryption at rest

```env
API_CALL_LOGGER_ENCRYPT=true
API_CALL_LOGGER_ENCRYPTION_KEY=          # optional dedicated key; empty = APP_KEY (APP_PREVIOUS_KEYS rotation works)
API_CALL_LOGGER_ENCRYPT_COLUMNS=request_body,response_body,request_headers,response_headers,query
```

- Payload columns are encrypted before they are written, and the model decrypts them when read.
- Switching encryption on or off never breaks old rows. Encrypted and plain records are both read back.
- With the wrong key, a record shows `_encrypted: <cannot decrypt>` instead of throwing.
- Queued log jobs are always encrypted on the queue.
- Encrypted columns can't be searched with SQL. Filter on the plain columns instead: provider, path, status, tenant, correlation id.

## Erasure requests (GDPR / NDPR)

```php
ApiCallLogger::forget(userId: '42');
ApiCallLogger::forget(tenant: 'INST-01');
ApiCallLogger::forget(loggable: $customer);
```

```bash
php artisan api-call-logger:forget --user=42
php artisan api-call-logger:forget --tenant=INST-01 --force
```

## Retrying calls

```php
$decision = ApiCallLogger::retryDecision($log);   // ->blockers, ->confirmations, ->allowed($force)

$newLog = ApiCallLogger::retry($log);              // safe call: replayed
$newLog = ApiCallLogger::retry($log, force: true); // risky call: you accept the confirmations
```

Replaying a payment can charge twice, so **you decide how strict retry is**:

```php
'retry' => [
    'enabled' => true,
    'safe_methods' => ['GET', 'HEAD', 'OPTIONS'],   // retried without confirmation
    'unsafe_methods' => 'confirm',                 // allow | confirm | deny for everything else
    'require_idempotency_key' => false,            // block unsafe retries that have no idempotency key
    'only_failed' => true,                         // confirm before retrying a call that succeeded
    'max_per_log' => 3,                            // retries per original record (null = unlimited)
    'lock_seconds' => 30,                          // block a parallel retry of the same record
],
```

| Outcome | When | Result |
| --- | --- | --- |
| Blocked | retry disabled, inbound webhook, payload redacted/masked/truncated, multipart/binary body, `deny` mode, missing required idempotency key, `max_per_log` reached, retry already running | `RetryNotPossible`. `force` does not help |
| Needs confirmation | non-idempotent method in `confirm` mode, or a call that already succeeded with `only_failed` | `RetryNeedsConfirmation` unless `force: true`. The dashboard shows the risks and an "I understand" checkbox |
| Allowed | everything else | Replayed and logged with `retry_of_id` pointing at the original |

Credentials are never stored, so retry gets authentication headers from your config or a resolver:

```php
'providers' => [
    'vtpass' => [
        'hosts' => ['sandbox.vtpass.com'],
        'retry_headers' => ['api-key' => env('VTPASS_API_KEY'), 'secret-key' => env('VTPASS_SECRET_KEY')],
    ],
],

// or at runtime (e.g. credentials stored in a settings table)
ApiCallLogger::retryHeadersUsing('masspay', fn ($log) => [
    'Authorization' => 'Bearer ' . Setting::resolve('masspay.api_key'),
]);
```

## Dashboard

Visit `/api-call-logs` (configurable with `dashboard.route_prefix`). Access is controlled by two gates.
Like Telescope, they **only allow the `local` environment** until you define them:

```php
// App\Providers\AppServiceProvider::boot()
Gate::define('viewApiCallLogs', fn ($user) => $user->hasRole('super_admin'));
Gate::define('retryApiCallLogs', fn ($user, $log = null) => $user->hasRole('super_admin'));
```

Add `auth` (or your admin guard) to `dashboard.middleware`, or turn the dashboard off with
`API_CALL_LOGGER_DASHBOARD_ENABLED=false` if you use Filament (see below).

### Using it from Filament

Point a resource at the package model:

```php
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

class ApiCallLogResource extends Resource
{
    protected static ?string $model = ApiCallLog::class;
    // columns: provider, method, path, context, status_code, success, duration_ms, created_at
}

// Retry header action: show the risks, retry with force once confirmed
Action::make('retry')
    ->visible(fn (ApiCallLog $record) => ! ApiCallLogger::retryDecision($record)->blocked())
    ->requiresConfirmation()
    ->modalDescription(fn (ApiCallLog $record) => implode(' ', ApiCallLogger::retryDecision($record)->confirmations) ?: 'Re-send this request?')
    ->action(fn (ApiCallLog $record) => ApiCallLogger::retry($record, force: true));
```

## Querying

```php
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;

ApiCallLog::provider('vtpass')->failed()->where('created_at', '>=', now()->subHour())->count();
ApiCallLog::outbound()->slowerThan(4000)->latest()->get();
ApiCallLog::where('correlation_id', $id)->get();
$transactionLogs = ApiCallLog::where('loggable_type', $tx->getMorphClass())->where('loggable_id', $tx->id)->get();
```

## Alerts

```php
Event::listen(ApiCallLogged::class, function (ApiCallLogged $event) {
    if (! $event->log->success && $event->log->provider === 'masspay') {
        Notification::route('slack', config('services.slack.ops'))->notify(new ProviderFailing($event->log));
    }
});
```

## Queueing and pruning

```env
API_CALL_LOGGER_QUEUE=true          # write log records from a queued job
API_CALL_LOGGER_PRUNE_DAYS=30       # null keeps records forever
```

```php
// routes/console.php
Schedule::command('api-call-logger:prune')->daily();   // or: Schedule::command('model:prune')->daily();
```

`php artisan api-call-logger:prune --days=7 --provider=vtpass`

## Migrating from a project-level `ThirdPartyApiLogger`

| Before | After |
| --- | --- |
| `$start = microtime(true); $res = Http::...->post(); ThirdPartyApiLogger::log(...)` | `$res = Http::withApiLogging('vtpass', $context)->...->post()` |
| `ThirdPartyApiLogger::log(provider:, method:, endpoint:, ...)` | `ApiCallLogger::log(provider:, method:, url:, ...)` |
| `ThirdPartyApiLogger::redact($x)` | `ApiCallLogger::redactor()->redact($x)` |
| `institution_code` column | `tenant` (set with `withTenant()` / `resolveTenantUsing()`) |
| `masspay_transaction_id` column | `loggable` (any model) |
| `LogIncomingWebhook` middleware | `api-call-logger.inbound` |
| Per-provider `match` in the Filament Retry action | `providers.*.retry_headers` / `retryHeadersUsing()` |

## Testing

```bash
composer test
```

Tests use in-memory SQLite when `pdo_sqlite` is installed. Otherwise they use the MySQL
database configured in `phpunit.xml.dist` (`api_call_logger_test`).

## License

MIT
