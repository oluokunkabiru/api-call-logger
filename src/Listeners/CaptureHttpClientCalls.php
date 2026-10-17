<?php

namespace Oluokunkabiru\ApiCallLogger\Listeners;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Oluokunkabiru\ApiCallLogger\ApiCallLogger;
use Oluokunkabiru\ApiCallLogger\Models\ApiCallLog;
use Oluokunkabiru\ApiCallLogger\Support\Payload;

/**
 * Logs every Laravel Http client call when `capture.http_client` is enabled.
 */
class CaptureHttpClientCalls
{
    public function __construct(protected ApiCallLogger $logger, protected array $config = []) {}

    public function handleResponse(ResponseReceived $event): void
    {
        $psr = $event->response->toPsrResponse();

        if ($this->logger->isHandled($psr) || ! $this->shouldCapture($event->request)) {
            return;
        }

        $seconds = $event->response->handlerStats()['total_time'] ?? $event->response->transferStats?->getTransferTime();

        $this->logger->record([
            'direction' => ApiCallLog::OUTBOUND,
            'method' => $event->request->method(),
            'url' => $event->request->url(),
            'request_headers' => $event->request->headers(),
            'request_body' => $this->requestBody($event->request),
            'status_code' => $event->response->status(),
            'duration_ms' => $seconds !== null ? $seconds * 1000 : null,
            'response_headers' => $event->response->headers(),
            'response_body' => Payload::fromMessage($psr, $this->logger->maxBodyBytes()),
        ]);
    }

    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        // The ConnectionException wraps the Guzzle exception the middleware saw.
        $exception = $event->exception;

        if ($this->logger->isHandled($exception->getPrevious())
            || $this->logger->isHandled($event->request->toPsrRequest())
            || ! $this->shouldCapture($event->request)) {
            return;
        }

        $this->logger->record([
            'direction' => ApiCallLog::OUTBOUND,
            'method' => $event->request->method(),
            'url' => $event->request->url(),
            'request_headers' => $event->request->headers(),
            'request_body' => $this->requestBody($event->request),
            'success' => false,
            'error' => $exception->getMessage(),
        ]);
    }

    protected function shouldCapture(Request $request): bool
    {
        $host = strtolower((string) parse_url($request->url(), PHP_URL_HOST));
        $only = (array) ($this->config['only_hosts'] ?? []);
        $except = (array) ($this->config['except_hosts'] ?? []);

        if ($only && ! Str::is(array_map('strtolower', $only), $host)) {
            return false;
        }

        return ! ($except && Str::is(array_map('strtolower', $except), $host));
    }

    protected function requestBody(Request $request): ?array
    {
        return Payload::fromMessage($request->toPsrRequest(), $this->logger->maxBodyBytes());
    }
}
