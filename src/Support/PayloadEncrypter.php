<?php

namespace Oluokunkabiru\ApiCallLogger\Support;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Resolves the encrypter used for payload columns: a dedicated
 * API_CALL_LOGGER_ENCRYPTION_KEY when set, otherwise the app encrypter (APP_KEY,
 * including APP_PREVIOUS_KEYS rotation).
 */
class PayloadEncrypter
{
    public static function make(): StringEncrypter
    {
        $key = config('api-call-logger.encryption.key');

        if (! $key) {
            return app('encrypter');
        }

        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new Encrypter($key, config('app.cipher', 'AES-256-CBC'));
    }

    public static function shouldEncrypt(string $column): bool
    {
        return (bool) config('api-call-logger.encryption.enabled', false)
            && in_array($column, (array) config('api-call-logger.encryption.columns', []), true);
    }

    /**
     * Laravel payloads are base64 JSON objects starting with {"iv":...
     */
    public static function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, 'eyJpdiI6');
    }
}
