<?php

namespace Oluokunkabiru\ApiCallLogger\Support;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Turns raw HTTP bodies and header bags into plain arrays that fit a JSON column.
 */
class Payload
{
    /**
     * Read a PSR-7 body without consuming it for the code that sent/received it.
     */
    public static function fromMessage(MessageInterface $message, int $maxBytes): ?array
    {
        return static::fromStream($message->getBody(), $message->getHeaderLine('Content-Type'), $maxBytes);
    }

    public static function fromStream(StreamInterface $stream, ?string $contentType, int $maxBytes): ?array
    {
        $size = $stream->getSize();

        if ($size === 0) {
            return null;
        }

        // Multipart bodies usually wrap uploaded files — never pull them into memory.
        if (str_contains(strtolower((string) $contentType), 'multipart/')) {
            return ['_multipart' => '<' . ($size ?? 'unknown') . ' bytes>'];
        }

        if (! $stream->isSeekable() || $size === null || $size > $maxBytes) {
            return ['_body' => '<not captured' . ($size !== null ? " {$size} bytes" : '') . '>'];
        }

        $position = $stream->tell();
        $stream->rewind();
        $contents = $stream->getContents();
        $stream->seek($position);

        return static::fromString($contents, $contentType);
    }

    /**
     * Decode a body by content type: JSON and form bodies become arrays,
     * multipart/binary bodies become a size marker, anything else is kept raw.
     */
    public static function fromString(?string $body, ?string $contentType = null): ?array
    {
        if ($body === null || $body === '') {
            return null;
        }

        $contentType = strtolower((string) $contentType);

        if (str_contains($contentType, 'multipart/')) {
            return ['_multipart' => '<' . strlen($body) . ' bytes>'];
        }

        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        if (json_last_error() === JSON_ERROR_NONE && $json !== null) {
            return ['_body' => $json];
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $form);

            return $form;
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            return ['_binary' => '<' . strlen($body) . ' bytes>'];
        }

        return ['_body' => $body];
    }

    /**
     * Collapse ['Accept' => ['application/json']] into ['Accept' => 'application/json'].
     */
    public static function headers(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            $flat[$name] = is_array($values) ? implode(', ', $values) : $values;
        }

        return $flat;
    }

    /**
     * Split a URL into [url-without-query, query-array].
     */
    public static function splitUrl(string $url): array
    {
        $query = [];
        $parts = explode('?', $url, 2);

        if (isset($parts[1]) && $parts[1] !== '') {
            parse_str($parts[1], $query);
        }

        return [$parts[0], $query];
    }
}
