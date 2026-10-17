<?php

/**
 * API Call Logger Configuration
 *
 * Publish this file to your Laravel project:
 *   php artisan vendor:publish --tag=api-call-logger-config
 *
 * Then edit config/api-call-logger.php to customise what gets logged.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Enable API Call Logging
    |--------------------------------------------------------------------------
    | Master switch. When false nothing is written, but your API calls keep
    | working exactly as before.
    |
    */
    'enabled' => env('API_CALL_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    | Table, database connection and Eloquent model used for log records.
    | The model may be swapped for your own class extending
    | Oluokunkabiru\ApiCallLogger\Models\ApiCallLog.
    |
    */
    'table' => env('API_CALL_LOGGER_TABLE', 'api_call_logs'),

    'connection' => env('API_CALL_LOGGER_DB_CONNECTION'),

    'model' => env('API_CALL_LOGGER_MODEL', Oluokunkabiru\ApiCallLogger\Models\ApiCallLog::class),

    /*
    |--------------------------------------------------------------------------
    | Run Package Migrations
    |--------------------------------------------------------------------------
    | Load the package migration automatically. Set to false if you publish
    | the migration (--tag=api-call-logger-migrations) to customise it.
    |
    */
    'run_migrations' => env('API_CALL_LOGGER_RUN_MIGRATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Persist log records on a queue instead of during the request, to keep
    | logging off the hot path of your API calls.
    |
    */
    'queue' => [
        'enabled' => env('API_CALL_LOGGER_QUEUE', false),
        'connection' => env('API_CALL_LOGGER_QUEUE_CONNECTION'),
        'queue' => env('API_CALL_LOGGER_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Http Client Capture
    |--------------------------------------------------------------------------
    | When enabled, EVERY outbound call made through Laravel's Http client is
    | logged, without changing any code. Narrow it down with `only_hosts` /
    | `except_hosts` (wildcards allowed, e.g. '*.vtpass.com').
    |
    | Calls made with Http::withApiLogging() are never logged twice.
    |
    */
    'capture' => [
        'http_client' => env('API_CALL_LOGGER_CAPTURE_HTTP_CLIENT', false),
        'only_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_CAPTURE_ONLY_HOSTS', ''))))),
        'except_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_CAPTURE_EXCEPT_HOSTS', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Name the third parties you talk to. `hosts` maps a request host to the
    | provider name (used by automatic capture and the inbound middleware when
    | no provider is given). `retry_headers` are the headers re-sent when a
    | logged call is retried — credentials are never stored in the log, so
    | they must come from here (or from ApiCallLogger::retryHeadersUsing()).
    |
    | 'vtpass' => [
    |     'hosts' => ['sandbox.vtpass.com', 'vtpass.com'],
    |     'retry_headers' => [
    |         'api-key' => env('VTPASS_API_KEY'),
    |         'secret-key' => env('VTPASS_SECRET_KEY'),
    |     ],
    | ],
    |
    */
    'providers' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    | Keys are matched case-insensitively and ignoring '-' and '_', at any
    | depth of a body, query string or header bag ('Secret-Key', 'secret_key'
    | and 'secretKey' all match 'secret_key').
    |
    | `always` keys (credentials) are redacted no matter what.
    | `sensitive` keys (PII / KYC) are redacted unless `enabled` is false —
    | handy for debugging in a non-production environment.
    |
    | Strings longer than `max_string_length` are replaced by a size marker
    | (base64 images, HTML pages, ...). Set it to null to keep them whole.
    |
    */
    'redaction' => [
        'enabled' => env('API_CALL_LOGGER_REDACT_SENSITIVE', true),

        'replacement' => env('API_CALL_LOGGER_REDACT_REPLACEMENT', '[REDACTED]'),

        'always' => [
            'password', 'password_confirmation', 'current_password', 'new_password',
            'secret', 'secret_key', 'client_secret', 'org_secret_key', 'api_key', 'x_api_key', 'apikey',
            'app_id', 'authorization', 'proxy_authorization', 'bearer', 'cookie', 'set_cookie',
            'token', 'access_token', 'refresh_token', 'id_token', 'otp',
            'pin', 'transaction_pin', 'card_pin', 'cvv', 'cvv2', 'security_code', 'pan', 'card_number',
            // Extra keys from .env, comma separated: API_CALL_LOGGER_REDACT_KEYS=merchant_key,signature
            ...array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_REDACT_KEYS', ''))))),
        ],

        'sensitive' => [
            'bvn', 'nin', 'ssn', 'document_id_no', 'id_value', 'unique_code',
            'image_encoding', 'image_url', 'profile_image_url', 'selfie', 'photo',
            // Extra keys from .env, comma separated: API_CALL_LOGGER_SENSITIVE_KEYS=dob,address
            ...array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_SENSITIVE_KEYS', ''))))),
        ],

        'max_string_length' => ($length = env('API_CALL_LOGGER_MAX_STRING_LENGTH', 500)) === null || $length === '' ? null : (int) $length,

        'max_depth' => (int) env('API_CALL_LOGGER_MAX_DEPTH', 10),

        /*
        | Value patterns — catch secrets by what they look like, wherever they
        | appear (any key, free-text strings, URL paths). Each one can be
        | switched on or off on its own.
        */
        'patterns' => [
            // Luhn-valid 13–19 digit card numbers → 411111******1111 (PCI-DSS first 6 / last 4)
            'card_numbers' => env('API_CALL_LOGGER_MASK_CARD_NUMBERS', true),

            // JSON Web Tokens (eyJ....eyJ....sig) → [REDACTED:jwt]
            'jwt' => env('API_CALL_LOGGER_MASK_JWT', true),

            // "Bearer <token>" / "Basic <credentials>" inside any string
            'auth_schemes' => env('API_CALL_LOGGER_MASK_AUTH_SCHEMES', true),

            // jo***@gmail.com
            'emails' => env('API_CALL_LOGGER_MASK_EMAILS', false),

            // +234******5678 — keeps the last 4 digits
            'phones' => env('API_CALL_LOGGER_MASK_PHONES', false),

            // Digit runs of at least this length in URL paths (BVN, NIN, NUBAN, phone
            // numbers in /kyc/bvn/22222222222) → *******2222. Null switches it off.
            'url_digits_min_length' => ($digits = env('API_CALL_LOGGER_MASK_URL_DIGITS', 10)) === null || $digits === '' ? null : (int) $digits,

            // Your own regexes, applied to every string: pattern => replacement
            'custom' => [
                // '/sk_(live|test)_[A-Za-z0-9]+/' => '[REDACTED:paystack-key]',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption At Rest
    |--------------------------------------------------------------------------
    | Encrypt payload columns with your APP_KEY (or a dedicated key) before
    | they are written. Records stay readable after switching it on or off,
    | old rows are decrypted (or read plain) transparently. Queued log jobs are
    | always encrypted on the queue.
    |
    | A dedicated key ("base64:..." — generate with `php artisan key:generate --show`)
    | lets you rotate it independently of APP_KEY.
    |
    */
    'encryption' => [
        'enabled' => env('API_CALL_LOGGER_ENCRYPT', false),
        'key' => env('API_CALL_LOGGER_ENCRYPTION_KEY'),
        'columns' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_ENCRYPT_COLUMNS', 'request_body,response_body,request_headers,response_headers,query'))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bodies & Headers
    |--------------------------------------------------------------------------
    | Bodies bigger than `max_body_bytes` (or non-seekable streams) are not
    | read; only their size is recorded.
    |
    */
    'log_headers' => env('API_CALL_LOGGER_LOG_HEADERS', true),

    'max_body_bytes' => (int) env('API_CALL_LOGGER_MAX_BODY_BYTES', 512 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Retry Attempts
    |--------------------------------------------------------------------------
    | Number the attempts made by Http::retry() (attempt 1, 2, 3 ...). An
    | attempt counts as a retry when it repeats the previous request (same
    | method and URL) right after that request failed.
    |
    */
    'track_attempts' => env('API_CALL_LOGGER_TRACK_ATTEMPTS', true),

    /*
    |--------------------------------------------------------------------------
    | Correlation
    |--------------------------------------------------------------------------
    | Every call logged during one request shares a correlation id.
    |
    | propagate_to_jobs  Carry the id into queued jobs dispatched by the request
    |                    (Laravel 11+ Context), so request → job → provider call
    |                    stays one flow.
    | share_with_logs    Also add the id to every Log:: line (visible context).
    | request_header     Adopt the caller's id from this incoming header, e.g.
    |                    X-Request-Id, or `traceparent` to reuse the W3C /
    |                    OpenTelemetry trace id.
    | forward_header     Send the id to providers in this header, e.g.
    |                    X-Correlation-ID. Null sends nothing extra.
    |
    */
    'correlation' => [
        'propagate_to_jobs' => env('API_CALL_LOGGER_CORRELATION_TO_JOBS', true),
        'share_with_logs' => env('API_CALL_LOGGER_CORRELATION_IN_LOGS', false),
        'request_header' => env('API_CALL_LOGGER_CORRELATION_REQUEST_HEADER'),
        'forward_header' => env('API_CALL_LOGGER_CORRELATION_FORWARD_HEADER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    | `headers` are the header names recognised as idempotency keys; the
    | value is stored in the `idempotency_key` column and re-sent on retry,
    | so the provider can recognise a duplicate.
    |
    | `auto_generate` adds a fresh key (first header name) to outbound
    | POST/PUT/PATCH/DELETE calls made with Http::withApiLogging() that do not
    | already carry one. Only enable it for providers that support it.
    |
    */
    'idempotency' => [
        'headers' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_IDEMPOTENCY_HEADERS', 'Idempotency-Key,X-Idempotency-Key'))))),
        'auto_generate' => env('API_CALL_LOGGER_AUTO_IDEMPOTENCY_KEY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Rules
    |--------------------------------------------------------------------------
    | Replaying a payment can charge twice, so you decide how strict retry is.
    |
    | enabled                  Master switch for retry (code and dashboard).
    | safe_methods             Retried without any confirmation.
    | unsafe_methods           Every other method: 'allow' (no confirmation),
    |                          'confirm' (caller must pass force / tick the box
    |                          in the dashboard) or 'deny' (never).
    | require_idempotency_key  Refuse unsafe retries that have no stored
    |                          idempotency key, even when forced.
    | only_failed              Ask for confirmation before retrying a call
    |                          that already succeeded.
    | max_per_log              Retries allowed for one record (null = no limit).
    | lock_seconds             Block a second retry of the same record while
    |                          one is running (0 = no lock).
    |
    */
    'retry' => [
        'enabled' => env('API_CALL_LOGGER_RETRY_ENABLED', true),
        'safe_methods' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_RETRY_SAFE_METHODS', 'GET,HEAD,OPTIONS'))))),
        'unsafe_methods' => env('API_CALL_LOGGER_RETRY_UNSAFE', 'confirm'),
        'require_idempotency_key' => env('API_CALL_LOGGER_RETRY_REQUIRE_IDEMPOTENCY_KEY', false),
        'only_failed' => env('API_CALL_LOGGER_RETRY_ONLY_FAILED', true),
        'max_per_log' => ($max = env('API_CALL_LOGGER_RETRY_MAX_PER_LOG', 3)) === null || $max === '' ? null : (int) $max,
        'lock_seconds' => (int) env('API_CALL_LOGGER_RETRY_LOCK_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Requests (Webhooks)
    |--------------------------------------------------------------------------
    | Settings for the `api-call-logger.inbound` middleware. When a route does
    | not pass a provider (->middleware('api-call-logger.inbound:fyatu')), the
    | provider is read from the path segment after `provider_segment_after`:
    | /api/webhooks/flutterwave  →  'flutterwave'.
    |
    */
    'inbound' => [
        'context' => env('API_CALL_LOGGER_INBOUND_CONTEXT', 'inbound-webhook'),
        'provider_segment_after' => env('API_CALL_LOGGER_INBOUND_SEGMENT', 'webhooks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    | Records older than this many days are removed by
    | `php artisan api-call-logger:prune` or Laravel's `model:prune`.
    | Null (API_CALL_LOGGER_PRUNE_DAYS=null) keeps records forever.
    |
    */
    'prune_after_days' => ($days = env('API_CALL_LOGGER_PRUNE_DAYS', 30)) === null || $days === '' ? null : (int) $days,

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    | A self-contained log browser at /{route_prefix}. Access is decided by the
    | `viewApiCallLogs` gate (and `retryApiCallLogs` for the Retry button).
    | Both only allow the `local` environment until you define them yourself:
    |
    |   Gate::define('viewApiCallLogs', fn ($user) => $user->is_admin);
    |
    */
    'dashboard' => [
        'enabled' => env('API_CALL_LOGGER_DASHBOARD_ENABLED', true),
        'route_prefix' => env('API_CALL_LOGGER_ROUTE_PREFIX', 'api-call-logs'),
        'middleware' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_CALL_LOGGER_DASHBOARD_MIDDLEWARE', 'web'))))),
        'per_page' => (int) env('API_CALL_LOGGER_DASHBOARD_PER_PAGE', 25),
    ],

];
