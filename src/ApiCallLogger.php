<?php

namespace Oluokunkabiru\ApiCallLogger;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Oluokunkabiru\ApiCallLogger\Events\ApiCallLogged;
use Oluokunkabiru\ApiCallLogger\Exceptions\RetryNeedsConfirmation;
use Oluokunkabiru\ApiCallLogger\Exceptions\RetryNotPossible;
use Oluokunkabiru\ApiCallLogger\Jobs\PersistApiCallLog;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;
use Oluokunkabiru\ApiCallLogger\Support\AttemptTracker;
use Oluokunkabiru\ApiCallLogger\Support\Payload;
use Oluokunkabiru\ApiCallLogger\Support\ProviderResolver;
use Oluokunkabiru\ApiCallLogger\Support\Redactor;
use Oluokunkabiru\ApiCallLogger\Support\RetryDecision;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use WeakMap;

/**
 * Centralized, provider-agnostic logger for third-party API calls and incoming webhooks.
 *
 * Logging is best-effort: a failure to write a log record is reported to the
 * application log and swallowed, so it can never break a live API call.
 */
class ApiCallLogger
{
    public const CONTEXT_KEY = 'api_call_logger_correlation_id';

    protected ?Closure $tenantResolver = null;

    protected ?Closure $userResolver = null;

    /** @var array<string, Closure> */
    protected array $retryHeaderResolvers = [];

    protected ?string $tenant = null;

    protected ?string $correlationId = null;

    protected array $meta = [];

    /** PSR messages already logged by the middleware, so automatic capture skips them. */
    protected WeakMap $handled;

    public function __construct(
        protected Redactor $redactor,
        protected ProviderResolver $providers,
        protected array $config = [],
    ) {
        $this->handled = new WeakMap();
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    // ── Logging ───────────────────────────────────────────

    /**
     * Log a completed API call made by any means (Http client, SOAP, cURL, SDK, ...).
     */
    public function log(
        string $provider,
        string $method,
        string $url,
        array|string|null $request = null,
        array|string|null $response = null,
        ?int $status = null,
        ?bool $success = null,
        ?float $durationMs = null,
        ?string $context = null,
        array $requestHeaders = [],
        array $responseHeaders = [],
        ?string $error = null,
        ?Model $loggable = null,
        array $meta = [],
        string $direction = ApiCallLog::OUTBOUND,
    ): ?ApiCallLog {
        return $this->record([
            'direction' => $direction,
            'provider' => $provider,
            'context' => $context,
            'method' => $method,
            'url' => $url,
            'request_headers' => $requestHeaders,
            'request_body' => is_string($request) ? Payload::fromString($request) : $request,
            'status_code' => $status,
            'success' => $success,
            'duration_ms' => $durationMs,
            'response_headers' => $responseHeaders,
            'response_body' => is_string($response) ? Payload::fromString($response) : $response,
            'error' => $error,
            'loggable' => $loggable,
            'meta' => $meta,
        ]);
    }

    /**
     * Time a callable and log it. The callable may return an Http client
     * Response (status, headers and body are read from it) or anything else
     * (stored as the response body).
     */
    public function measure(string $provider, string $method, string $url, callable $call, array|string|null $request = null, ?string $context = null, ?Model $loggable = null): mixed
    {
        $start = hrtime(true);

        try {
            $result = $call();
        } catch (Throwable $e) {
            $this->log($provider, $method, $url, $request, null, null, false, $this->elapsed($start), $context, error: $e->getMessage(), loggable: $loggable);

            throw $e;
        }

        if ($result instanceof Response) {
            $this->log(
                $provider, $method, $url, $request,
                $result->body(), $result->status(), $result->successful(), $this->elapsed($start), $context,
                responseHeaders: Payload::headers($result->headers()),
                loggable: $loggable,
            );
        } else {
            $this->log($provider, $method, $url, $request, is_array($result) ? $result : ['_result' => $result], null, true, $this->elapsed($start), $context, loggable: $loggable);
        }

        return $result;
    }

    /**
     * Normalise, redact and persist one log record.
     *
     * Accepted keys: direction, provider, context, method, url, query, request_headers,
     * request_body, status_code, success, duration_ms, response_headers, response_body,
     * error, tenant, user_id, loggable (Model), correlation_id, retry_of_id, meta.
     */
    public function record(array $attributes, bool $sync = false): ?ApiCallLog
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $attributes = $this->prepare($attributes);

            if (! $sync && ($this->config['queue']['enabled'] ?? false)) {
                PersistApiCallLog::dispatch($attributes)
                    ->onConnection($this->config['queue']['connection'] ?? null)
                    ->onQueue($this->config['queue']['queue'] ?? null);

                return null;
            }

            return $this->persist($attributes);
        } catch (Throwable $e) {
            Log::error('ApiCallLogger: failed to log ' . ($attributes['provider'] ?? 'unknown') . ' call to ' . ($attributes['url'] ?? '?') . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Write already-prepared attributes to the database.
     */
    public function persist(array $attributes): ApiCallLog
    {
        $model = $this->config['model'] ?? ApiCallLog::class;

        /** @var ApiCallLog $log */
        $log = $model::query()->create($attributes);

        event(new ApiCallLogged($log));

        return $log;
    }

    protected function prepare(array $attributes): array
    {
        [$url, $query] = Payload::splitUrl((string) ($attributes['url'] ?? ''));
        $query = array_merge($query, (array) ($attributes['query'] ?? []));
        $status = isset($attributes['status_code']) && $attributes['status_code'] > 0 ? (int) $attributes['status_code'] : null;
        $logHeaders = $this->config['log_headers'] ?? true;

        $loggable = $attributes['loggable'] ?? null;
        unset($attributes['loggable']);

        // Mask secrets in the URL path itself (/kyc/bvn/22222222222).
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $maskedPath = $this->redactor->maskPath($path);
        if ($maskedPath !== $path && str_ends_with($url, $path)) {
            $url = substr($url, 0, strlen($url) - strlen($path)) . $maskedPath;
        }

        return array_merge($attributes, [
            'direction' => $attributes['direction'] ?? ApiCallLog::OUTBOUND,
            'provider' => strtolower((string) ($attributes['provider'] ?? $this->providers->forUrl($url))),
            'method' => strtoupper((string) ($attributes['method'] ?? 'GET')),
            'url' => Str::limit($url, 2048, ''),
            'host' => parse_url($url, PHP_URL_HOST) ?: null,
            'path' => Str::limit($maskedPath, 1024, ''),
            'query' => $query ? $this->redactor->redact($query) : null,
            'request_headers' => $logHeaders && ! empty($attributes['request_headers']) ? $this->redactor->redact(Payload::headers($attributes['request_headers'])) : null,
            'request_body' => isset($attributes['request_body']) ? $this->redactor->redact($attributes['request_body']) : null,
            'status_code' => $status,
            'success' => $attributes['success'] ?? ($status !== null && $status >= 200 && $status < 300),
            'duration_ms' => isset($attributes['duration_ms']) ? (int) round($attributes['duration_ms']) : null,
            'response_headers' => $logHeaders && ! empty($attributes['response_headers']) ? $this->redactor->redact(Payload::headers($attributes['response_headers'])) : null,
            'response_body' => isset($attributes['response_body']) ? $this->redactor->redact($attributes['response_body']) : null,
            'error' => isset($attributes['error']) ? Str::limit((string) $attributes['error'], 5000) : null,
            'tenant' => $attributes['tenant'] ?? $this->resolveTenant(),
            'user_id' => $attributes['user_id'] ?? $this->resolveUser(),
            'loggable_type' => $loggable instanceof Model ? $loggable->getMorphClass() : ($attributes['loggable_type'] ?? null),
            'loggable_id' => $loggable instanceof Model ? $loggable->getKey() : ($attributes['loggable_id'] ?? null),
            'correlation_id' => $attributes['correlation_id'] ?? $this->correlationId(),
            'idempotency_key' => Str::limit((string) ($attributes['idempotency_key'] ?? $this->idempotencyKeyFrom((array) ($attributes['request_headers'] ?? []))), 255, '') ?: null,
            'attempt' => max(1, (int) ($attributes['attempt'] ?? 1)),
            'meta' => array_merge($this->meta, (array) ($attributes['meta'] ?? [])) ?: null,
        ]);
    }

    // ── Http client integration ───────────────────────────

    /**
     * A PendingRequest that logs every call it makes:
     *
     *   ApiCallLogger::http('vtpass', 'buy-airtime')->withHeaders([...])->post($url, $payload);
     */
    public function http(?string $provider = null, ?string $context = null, array $options = []): PendingRequest
    {
        return Http::withMiddleware($this->middleware($provider, $context, $options));
    }

    /**
     * Guzzle middleware that logs the request/response passing through it.
     *
     * Options: loggable (Model), meta (array), tenant (string), retry_of_id (int),
     * success (callable(Response): bool), on_logged (callable(?ApiCallLog)), sync (bool).
     */
    public function middleware(?string $provider = null, ?string $context = null, array $options = []): callable
    {
        // Shared by every attempt Http::retry() sends through this middleware.
        $previous = new AttemptTracker;

        return function (callable $handler) use ($provider, $context, $options, $previous) {
            return function (RequestInterface $request, array $guzzleOptions) use ($handler, $provider, $context, $options, $previous) {
                if (! $this->enabled()) {
                    return $handler($request, $guzzleOptions);
                }

                $key = $request->getMethod() . ' ' . $request->getUri();
                $isRetry = ($this->config['track_attempts'] ?? true) && $previous->isRetryOf($key);
                $options['attempt'] = $isRetry ? $previous->attempt + 1 : 1;

                $request = $this->decorateOutgoing($request, $isRetry ? $previous->idempotencyKey : null);
                $previous->start($key, $options['attempt'], $this->idempotencyKeyFrom($request->getHeaders()));

                $start = hrtime(true);
                $requestBody = Payload::fromMessage($request, $this->maxBodyBytes());

                $onRejected = function ($reason) use ($request, $requestBody, $start, $provider, $context, $options) {
                    $response = $reason instanceof GuzzleRequestException ? $reason->getResponse() : null;

                    if ($response) {
                        $this->handled[$response] = true;
                    }
                    if ($reason instanceof Throwable) {
                        $this->handled[$reason] = true;
                    }
                    if (is_object($reason) && method_exists($reason, 'getRequest') && $reason->getRequest() instanceof RequestInterface) {
                        $this->handled[$reason->getRequest()] = true;
                    }

                    $error = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;
                    $this->recordExchange($request, $requestBody, $response, $error, $this->elapsed($start), $provider, $context, $options);

                    return Create::rejectionFor($reason);
                };

                try {
                    $promise = $handler($request, $guzzleOptions);
                } catch (Throwable $e) {
                    // Some handlers throw instead of returning a rejected promise.
                    return $onRejected($e);
                }

                return $promise->then(
                    function (ResponseInterface $response) use ($request, $requestBody, $start, $provider, $context, $options, $previous) {
                        $this->handled[$response] = true;
                        $previous->failed = $response->getStatusCode() >= 400;
                        $this->recordExchange($request, $requestBody, $response, null, $this->elapsed($start), $provider, $context, $options);

                        return $response;
                    },
                    $onRejected
                );
            };
        };
    }

    /**
     * Add the opt-in outgoing headers: correlation id and idempotency key.
     */
    protected function decorateOutgoing(RequestInterface $request, ?string $reuseIdempotencyKey): RequestInterface
    {
        $forward = $this->config['correlation']['forward_header'] ?? null;

        if ($forward && ! $request->hasHeader($forward)) {
            $request = $request->withHeader($forward, $this->correlationId());
        }

        $headers = (array) ($this->config['idempotency']['headers'] ?? []);

        if (($this->config['idempotency']['auto_generate'] ?? false)
            && $headers
            && ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && $this->idempotencyKeyFrom($request->getHeaders()) === null) {
            // Retries of the same call keep the same key, so the provider can deduplicate.
            $request = $request->withHeader($headers[0], $reuseIdempotencyKey ?? (string) Str::uuid());
        }

        return $request;
    }

    /**
     * The idempotency key found in a header bag, matched case-insensitively.
     */
    public function idempotencyKeyFrom(array $headers): ?string
    {
        $names = array_map('strtolower', (array) ($this->config['idempotency']['headers'] ?? []));

        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), $names, true)) {
                $value = is_array($value) ? reset($value) : $value;

                return $value !== '' && $value !== false && $value !== null ? (string) $value : null;
            }
        }

        return null;
    }

    protected function recordExchange(
        RequestInterface $request,
        ?array $requestBody,
        ?ResponseInterface $response,
        ?string $error,
        float $durationMs,
        ?string $provider,
        ?string $context,
        array $options,
    ): void {
        $success = null;

        if ($response && isset($options['success'])) {
            try {
                $success = (bool) $options['success'](new Response($response));
            } catch (Throwable) {
                $success = false;
            }
        } elseif (! $response) {
            $success = false;
        }

        $log = $this->record([
            'direction' => ApiCallLog::OUTBOUND,
            'provider' => $provider ?? $this->providers->forHost($request->getUri()->getHost()),
            'context' => $context,
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'request_headers' => $request->getHeaders(),
            'request_body' => $requestBody,
            'status_code' => $response?->getStatusCode(),
            'success' => $success,
            'duration_ms' => $durationMs,
            'response_headers' => $response?->getHeaders() ?? [],
            'response_body' => $response ? Payload::fromMessage($response, $this->maxBodyBytes()) : null,
            'error' => $error,
            'tenant' => $options['tenant'] ?? null,
            'loggable' => $options['loggable'] ?? null,
            'retry_of_id' => $options['retry_of_id'] ?? null,
            'attempt' => $options['attempt'] ?? 1,
            'meta' => $options['meta'] ?? [],
        ], (bool) ($options['sync'] ?? false));

        if (isset($options['on_logged'])) {
            $options['on_logged']($log);
        }
    }

    /**
     * Whether a PSR request/response was already logged by the middleware.
     */
    public function isHandled(?object $message): bool
    {
        return $message !== null && isset($this->handled[$message]);
    }

    // ── Retry ─────────────────────────────────────────────

    /**
     * What stands between this record and a retry, according to `api-call-logger.retry`:
     * hard blockers, and risks that need the caller's explicit confirmation ($force).
     */
    public function retryDecision(ApiCallLog $log): RetryDecision
    {
        $rules = $this->config['retry'] ?? [];
        $blockers = [];
        $confirmations = [];
        $method = strtoupper($log->method);
        $unsafe = ! in_array($method, array_map('strtoupper', (array) ($rules['safe_methods'] ?? ['GET', 'HEAD', 'OPTIONS'])), true);

        if (! ($rules['enabled'] ?? true)) {
            $blockers[] = 'Retry is disabled (api-call-logger.retry.enabled).';
        }

        if ($log->isInbound()) {
            $blockers[] = 'Inbound requests (webhooks) cannot be retried.';
        }

        if ($this->redactor->isLossy($log->request_body) || $this->redactor->isLossy($log->query) || $this->redactor->isLossy($log->path)) {
            $blockers[] = 'The logged request contains redacted, masked or truncated values; replaying it would send placeholders instead of real data.';
        }

        if (isset($log->request_body['_multipart']) || isset($log->request_body['_binary']) || isset($log->request_body['_encrypted'])) {
            $blockers[] = 'The request body was not stored (multipart, binary or undecryptable) and cannot be replayed.';
        }

        if ($unsafe) {
            match ($rules['unsafe_methods'] ?? 'confirm') {
                'deny' => $blockers[] = "{$method} calls may not be retried (api-call-logger.retry.unsafe_methods = deny).",
                'allow' => null,
                default => $confirmations[] = "{$method} is not idempotent: the provider may perform the operation again (e.g. a second charge or transfer).",
            };

            if (($rules['require_idempotency_key'] ?? false) && ! $log->idempotency_key) {
                $blockers[] = "No idempotency key was sent with the original {$method} call, so the provider cannot detect a duplicate.";
            }
        }

        if (($rules['only_failed'] ?? true) && $log->success) {
            $confirmations[] = 'This call already succeeded.';
        }

        $max = $rules['max_per_log'] ?? null;
        if ($max !== null && $log->exists && $this->retryCount($log) >= $max) {
            $blockers[] = "This call has already been retried {$max} time(s) (api-call-logger.retry.max_per_log).";
        }

        return new RetryDecision($blockers, $confirmations);
    }

    /**
     * Replay a logged outbound call and log the new attempt, linked to the
     * original record through retry_of_id.
     *
     * Credentials are never stored, so authentication headers come from
     * `providers.{name}.retry_headers`, ApiCallLogger::retryHeadersUsing() or $headers.
     * The stored idempotency key is re-sent, so providers can spot the duplicate.
     *
     * @param  bool  $force  Accept the risks listed in retryDecision()->confirmations.
     *
     * @throws RetryNotPossible       When a rule blocks the retry.
     * @throws RetryNeedsConfirmation When the retry is risky and $force is false.
     */
    public function retry(ApiCallLog $log, array $headers = [], bool $force = false): ?ApiCallLog
    {
        $decision = $this->retryDecision($log);

        if ($decision->blockers) {
            throw new RetryNotPossible(implode(' ', $decision->blockers), $decision);
        }

        if ($decision->confirmations && ! $force) {
            throw new RetryNeedsConfirmation(implode(' ', $decision->confirmations), $decision);
        }

        $rootId = $log->retry_of_id ?? $log->getKey();
        $lock = $this->retryLock($rootId);

        if ($lock && ! $lock->get()) {
            throw new RetryNotPossible('A retry of this call is already running.', $decision);
        }

        try {
            return $this->sendRetry($log, $rootId, $headers);
        } finally {
            $lock?->release();
        }
    }

    protected function sendRetry(ApiCallLog $log, int|string $rootId, array $headers): ?ApiCallLog
    {
        $headers = array_merge($this->replayableHeaders($log), $this->retryHeaders($log), $headers);
        $contentType = strtolower((string) collect($headers)->first(fn ($v, $k) => strtolower($k) === 'content-type'));

        $options = array_filter(['query' => $log->query ?: null]);
        $body = $log->request_body;

        if ($body !== null) {
            if (array_key_exists('_body', $body) && count($body) === 1) {
                $options['body'] = is_string($body['_body']) ? $body['_body'] : json_encode($body['_body']);
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                $options['form_params'] = $body;
            } else {
                $options['json'] = $body;
            }
        }

        $retried = null;

        try {
            $this->http($log->provider, $log->context ? 'retry:' . preg_replace('/^retry:/', '', $log->context) : 'retry', [
                'retry_of_id' => $rootId,
                'tenant' => $log->tenant,
                'meta' => array_filter(['retried_by' => $this->resolveUser()]),
                'sync' => true,
                'on_logged' => function ($new) use (&$retried) {
                    $retried = $new;
                },
            ])
                ->withHeaders($headers)
                ->send($log->method, $log->url, $options);
        } catch (ConnectionException) {
            // Already logged as a failed attempt by the middleware.
        }

        if ($retried && $log->loggable_type) {
            $retried->forceFill(['loggable_type' => $log->loggable_type, 'loggable_id' => $log->loggable_id])->save();
        }

        return $retried;
    }

    protected function retryCount(ApiCallLog $log): int
    {
        return $log->newQuery()->where('retry_of_id', $log->retry_of_id ?? $log->getKey())->count();
    }

    protected function retryLock(int|string $rootId): ?Lock
    {
        $seconds = (int) ($this->config['retry']['lock_seconds'] ?? 30);

        if ($seconds <= 0) {
            return null;
        }

        try {
            return Cache::lock("api-call-logger:retry:{$rootId}", $seconds);
        } catch (Throwable) {
            return null; // cache store without lock support
        }
    }

    protected function replayableHeaders(ApiCallLog $log): array
    {
        $skip = ['host', 'content-length', 'user-agent', 'cookie', 'transfer-encoding', 'connection'];

        return collect($log->request_headers ?? [])
            ->reject(fn ($value, $name) => $value === $this->redactor->replacement() || in_array(strtolower($name), $skip, true))
            ->all();
    }

    protected function retryHeaders(ApiCallLog $log): array
    {
        $headers = (array) ($this->providers->config($log->provider)['retry_headers'] ?? []);

        if (isset($this->retryHeaderResolvers[$log->provider])) {
            $headers = array_merge($headers, (array) ($this->retryHeaderResolvers[$log->provider])($log));
        }

        return array_filter($headers, fn ($value) => $value !== null && $value !== '');
    }

    // ── Data-subject erasure ──────────────────────────────

    /**
     * Delete every record of a user, a tenant and/or a related model
     * (GDPR / NDPR erasure requests). Returns the number of deleted records.
     */
    public function forget(?string $userId = null, ?string $tenant = null, ?Model $loggable = null): int
    {
        if ($userId === null && $tenant === null && $loggable === null) {
            throw new \InvalidArgumentException('Pass a user id, a tenant or a model to forget.');
        }

        $model = $this->config['model'] ?? ApiCallLog::class;

        return $model::query()->belongingTo($userId, $tenant, $loggable)->delete();
    }

    // ── Request-scoped context ────────────────────────────

    /**
     * Tag every call logged during this request/job with a tenant (institution, organisation, ...).
     */
    public function withTenant(?string $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    /**
     * Attach extra metadata to every call logged during this request/job.
     */
    public function withMeta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    public function withCorrelationId(string $correlationId): static
    {
        $this->correlationId = Str::limit($correlationId, 64, '');
        $this->shareCorrelationId();

        return $this;
    }

    /**
     * One id shared by every call logged during this request and, through
     * Laravel's Context, the queued jobs it dispatches.
     */
    public function correlationId(): string
    {
        if ($this->correlationId !== null) {
            return $this->correlationId;
        }

        $id = $this->correlationIdFromContext() ?? $this->correlationIdFromRequest() ?? (string) Str::uuid();

        $this->withCorrelationId($id);

        return $this->correlationId ?? $id;
    }

    protected function correlationIdFromContext(): ?string
    {
        if (! class_exists(\Illuminate\Support\Facades\Context::class)) {
            return null;
        }

        try {
            $context = \Illuminate\Support\Facades\Context::getFacadeRoot();

            return $context->get(self::CONTEXT_KEY) ?? $context->getHidden(self::CONTEXT_KEY);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Adopt the caller's id from `correlation.request_header`. For `traceparent`
     * (W3C Trace Context / OpenTelemetry) the 32-hex trace id is used.
     */
    protected function correlationIdFromRequest(): ?string
    {
        $header = $this->config['correlation']['request_header'] ?? null;

        if (! $header || ! app()->bound('request')) {
            return null;
        }

        $value = (string) app('request')->headers->get($header, '');

        if (strtolower($header) === 'traceparent') {
            return preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/i', trim($value), $m) ? strtolower($m[1]) : null;
        }

        return preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $value) ? $value : null;
    }

    protected function shareCorrelationId(): void
    {
        $config = $this->config['correlation'] ?? [];
        $toLogs = (bool) ($config['share_with_logs'] ?? false);

        if ((! ($config['propagate_to_jobs'] ?? true) && ! $toLogs) || ! class_exists(\Illuminate\Support\Facades\Context::class)) {
            return;
        }

        try {
            $context = \Illuminate\Support\Facades\Context::getFacadeRoot();
            $toLogs
                ? $context->add(self::CONTEXT_KEY, $this->correlationId)
                : $context->addHidden(self::CONTEXT_KEY, $this->correlationId);
        } catch (Throwable) {
            // Context unavailable — the id simply stays request-local.
        }
    }

    public function resolveTenantUsing(?callable $resolver): static
    {
        $this->tenantResolver = $resolver ? Closure::fromCallable($resolver) : null;

        return $this;
    }

    public function resolveUserUsing(?callable $resolver): static
    {
        $this->userResolver = $resolver ? Closure::fromCallable($resolver) : null;

        return $this;
    }

    /**
     * Provide authentication headers for retries of one provider:
     *
     *   ApiCallLogger::retryHeadersUsing('paystack', fn () => ['Authorization' => 'Bearer ' . config('services.paystack.secret')]);
     */
    public function retryHeadersUsing(string $provider, callable $resolver): static
    {
        $this->retryHeaderResolvers[strtolower($provider)] = Closure::fromCallable($resolver);

        return $this;
    }

    protected function resolveTenant(): ?string
    {
        if ($this->tenant !== null) {
            return $this->tenant;
        }

        try {
            $tenant = $this->tenantResolver ? ($this->tenantResolver)() : null;
        } catch (Throwable) {
            $tenant = null;
        }

        return $tenant !== null ? (string) $tenant : null;
    }

    protected function resolveUser(): ?string
    {
        try {
            $id = $this->userResolver ? ($this->userResolver)() : (app()->bound('auth') ? auth()->id() : null);
        } catch (Throwable) {
            $id = null;
        }

        return $id !== null ? (string) $id : null;
    }

    // ── Accessors ─────────────────────────────────────────

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    public function providers(): ProviderResolver
    {
        return $this->providers;
    }

    public function maxBodyBytes(): int
    {
        return (int) ($this->config['max_body_bytes'] ?? 512 * 1024);
    }

    protected function elapsed(int $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }
}
