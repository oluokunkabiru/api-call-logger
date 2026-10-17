<?php

namespace Oluokunkabiru\ApiCallLogger\Support;

/**
 * Strips credentials and PII out of bodies, query strings and headers, and
 * shrinks oversized strings, before anything is written to the log table.
 *
 * Two layers: key names (`always` / `sensitive` lists) and value patterns
 * (card numbers, JWTs, auth schemes, emails, phones, custom regexes) that are
 * caught wherever they appear.
 */
class Redactor
{
    public const MASK = '*';

    /** @var array<string, true> */
    protected array $always;

    /** @var array<string, true> */
    protected array $sensitive;

    protected array $patterns;

    public function __construct(
        array $always = [],
        array $sensitive = [],
        protected bool $redactSensitive = true,
        protected string $replacement = '[REDACTED]',
        protected ?int $maxStringLength = 500,
        protected int $maxDepth = 10,
        array $patterns = [],
    ) {
        $this->always = array_fill_keys(array_map([static::class, 'normalizeKey'], $always), true);
        $this->sensitive = array_fill_keys(array_map([static::class, 'normalizeKey'], $sensitive), true);
        $this->patterns = array_merge([
            'card_numbers' => false,
            'jwt' => false,
            'auth_schemes' => false,
            'emails' => false,
            'phones' => false,
            'url_digits_min_length' => null,
            'custom' => [],
        ], $patterns);
    }

    public static function fromConfig(array $config): static
    {
        return new static(
            always: $config['always'] ?? [],
            sensitive: $config['sensitive'] ?? [],
            redactSensitive: (bool) ($config['enabled'] ?? true),
            replacement: $config['replacement'] ?? '[REDACTED]',
            maxStringLength: $config['max_string_length'] ?? null,
            maxDepth: (int) ($config['max_depth'] ?? 10),
            patterns: $config['patterns'] ?? [],
        );
    }

    /**
     * 'Secret-Key', 'secret_key' and 'secretKey' all normalise to 'secretkey'.
     */
    public static function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $key));
    }

    public function shouldRedact(int|string $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $key = static::normalizeKey($key);

        return isset($this->always[$key]) || ($this->redactSensitive && isset($this->sensitive[$key]));
    }

    public function redact(mixed $value, int $depth = 0): mixed
    {
        if (is_array($value)) {
            if ($depth >= $this->maxDepth) {
                return '<max-depth>';
            }

            $clean = [];
            foreach ($value as $key => $item) {
                $clean[$key] = $this->shouldRedact($key) && $item !== null && $item !== ''
                    ? $this->replacement
                    : $this->redact($item, $depth + 1);
            }

            return $clean;
        }

        if (is_string($value)) {
            if ($this->maxStringLength !== null && strlen($value) > $this->maxStringLength) {
                return '<truncated ' . strlen($value) . ' bytes>';
            }

            return $this->maskString($value);
        }

        // Card numbers sent as JSON integers.
        if (is_int($value) && $this->patterns['card_numbers'] && strlen((string) $value) >= 13) {
            $masked = $this->maskString((string) $value);

            return $masked === (string) $value ? $value : $masked;
        }

        return $value;
    }

    /**
     * Apply the value patterns to one string.
     */
    public function maskString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if ($this->patterns['jwt']) {
            $value = preg_replace('/\beyJ[A-Za-z0-9_-]{5,}\.eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]+/', '[REDACTED:jwt]', $value);
        }

        if ($this->patterns['auth_schemes']) {
            $value = preg_replace('/\b(Bearer|Basic)\s+(?!\[REDACTED)[A-Za-z0-9\-._~+\/=]{8,}/i', '$1 ' . $this->replacement, $value);
        }

        if ($this->patterns['card_numbers']) {
            $value = preg_replace_callback('/(?<![\d.])(?:\d[ -]?){12,18}\d(?![\d.])/', function ($match) {
                $digits = preg_replace('/\D/', '', $match[0]);

                return static::passesLuhn($digits)
                    ? substr($digits, 0, 6) . str_repeat(self::MASK, strlen($digits) - 10) . substr($digits, -4)
                    : $match[0];
            }, $value);
        }

        if ($this->patterns['emails']) {
            $value = preg_replace_callback('/\b([A-Za-z0-9._%+-]{1,64})@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', function ($match) {
                return substr($match[1], 0, min(2, strlen($match[1]))) . str_repeat(self::MASK, 3) . '@' . $match[2];
            }, $value);
        }

        if ($this->patterns['phones']) {
            $value = preg_replace_callback('/(?<![\w.])\+?\d[\d\s-]{8,15}\d(?![\w.])/', function ($match) {
                $digits = preg_replace('/\D/', '', $match[0]);

                if (strlen($digits) < 10 || strlen($digits) > 15) {
                    return $match[0];
                }

                return (str_starts_with($match[0], '+') ? '+' : '') . str_repeat(self::MASK, strlen($digits) - 4) . substr($digits, -4);
            }, $value);
        }

        foreach ((array) $this->patterns['custom'] as $pattern => $replacement) {
            $value = @preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return $value;
    }

    /**
     * Mask long digit runs in URL path segments: /kyc/bvn/22222222222 → /kyc/bvn/*******2222.
     */
    public function maskPath(string $path): string
    {
        $min = $this->patterns['url_digits_min_length'];

        $path = $min
            ? preg_replace_callback('/(?<=\/|^)(\d{' . (int) $min . ',})(?=\/|$)/', fn ($m) => str_repeat(self::MASK, strlen($m[1]) - 4) . substr($m[1], -4), $path)
            : $path;

        return $this->maskString($path);
    }

    public static function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19 || ! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    public function replacement(): string
    {
        return $this->replacement;
    }

    /**
     * True when a stored payload lost information (redacted, masked or truncated
     * values), which makes it unsafe to replay as-is.
     */
    public function isLossy(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->isLossy($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && (
            $value === $this->replacement
            || str_contains($value, $this->replacement)
            || str_contains($value, '[REDACTED')
            || str_contains($value, str_repeat(self::MASK, 3))
            || $value === '<max-depth>'
            || str_starts_with($value, '<truncated ')
        );
    }
}
