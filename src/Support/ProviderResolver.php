<?php

namespace Oluokunkabiru\ApiCallLogger\Support;

use Illuminate\Support\Str;

/**
 * Maps a request host to the provider name configured in `api-call-logger.providers`.
 */
class ProviderResolver
{
    public function __construct(protected array $providers = []) {}

    public function forHost(?string $host): string
    {
        $host = strtolower((string) $host);

        foreach ($this->providers as $name => $provider) {
            foreach ((array) ($provider['hosts'] ?? []) as $pattern) {
                if (Str::is(strtolower($pattern), $host)) {
                    return (string) $name;
                }
            }
        }

        return $host !== '' ? $host : 'unknown';
    }

    public function forUrl(string $url): string
    {
        return $this->forHost(parse_url($url, PHP_URL_HOST));
    }

    public function config(string $provider): array
    {
        return $this->providers[$provider] ?? [];
    }
}
