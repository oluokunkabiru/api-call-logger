<?php

namespace Oluokunkabiru\ApiCallLogger\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Oluokunkabiru\ApiCallLogger\Support\PayloadEncrypter;

/**
 * JSON payload column that is encrypted at rest when `api-call-logger.encryption`
 * covers it. Encrypted values are stored as a JSON string, so the column stays
 * valid JSON, and both encrypted and plain rows are read back transparently —
 * switching encryption on or off never breaks existing records.
 */
class EncryptablePayload implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (is_string($decoded) && PayloadEncrypter::looksEncrypted($decoded)) {
            try {
                return json_decode(PayloadEncrypter::make()->decryptString($decoded), true);
            } catch (DecryptException) {
                return ['_encrypted' => '<cannot decrypt: wrong or rotated key>'];
            }
        }

        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! PayloadEncrypter::shouldEncrypt($key)) {
            return $json;
        }

        return json_encode(PayloadEncrypter::make()->encryptString($json));
    }
}
