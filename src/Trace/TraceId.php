<?php

declare(strict_types=1);

/**
 * Trace Identifier
 *
 * UUIDv7 (RFC 9562) trace identifier. Timestamp-sortable, globally unique,
 * compatible with every database, log tool, and tracing system. The 32-hex
 * form (no dashes) is the W3C Trace Context `trace-id`.
 *
 * Bit layout per RFC 9562 Section 5.7:
 *   [48: unix_ts_ms][4: ver=0111][12: rand_a][2: var=10][62: rand_b]
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Trace;

use PHPdot\Logs\Exception\InvalidIdentifierException;

final class TraceId
{
    /**
     * Create a trace id from its UUID form and creation time.
     *
     * @param string $uuid UUID formatted string (8-4-4-4-12).
     * @param int $timestamp Unix timestamp in milliseconds.
     */
    private function __construct(
        private readonly string $uuid,
        private readonly int $timestamp,
    ) {}

    /**
     * Generate a new UUIDv7 trace identifier per RFC 9562.
     *
     * @return self A fresh trace id.
     */
    public static function generate(): self
    {
        $timestamp = (int) (microtime(true) * 1000);

        return new self(self::uuidv7($timestamp), $timestamp);
    }

    /**
     * Parse a trace id from a string (UUID or 32-char hex form).
     *
     * The all-zero id is the W3C "invalid" sentinel and is rejected.
     *
     * @param string $id UUID (8-4-4-4-12) or 32-char hex string.
     *
     * @throws InvalidIdentifierException If the format is invalid or all-zero.
     *
     * @return self The parsed trace id.
     */
    public static function fromString(string $id): self
    {
        $hex = strtolower(str_replace('-', '', $id));

        if (preg_match('/^[0-9a-f]{32}\z/', $hex) !== 1 || $hex === str_repeat('0', 32)) {
            throw InvalidIdentifierException::traceId($id);
        }

        $timestamp = (int) hexdec(substr($hex, 0, 12));

        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );

        return new self($uuid, $timestamp);
    }

    /**
     * Get the trace id as a 32-character lowercase hex string (no dashes).
     *
     * This is the W3C Trace Context `trace-id` form.
     *
     * @return string 32 hex characters.
     */
    public function id(): string
    {
        return str_replace('-', '', $this->uuid);
    }

    /**
     * Get the trace id in UUID format (8-4-4-4-12).
     *
     * @return string UUID formatted string.
     */
    public function uuid(): string
    {
        return $this->uuid;
    }

    /**
     * Get the embedded timestamp in milliseconds since the Unix epoch.
     *
     * @return int Unix timestamp in milliseconds.
     */
    public function timestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * String representation returns the UUID format.
     *
     * @return string UUID formatted string.
     */
    public function __toString(): string
    {
        return $this->uuid;
    }

    /**
     * Generate a UUIDv7 per RFC 9562 Section 5.7.
     *
     * Byte-level construction:
     *   Octets 0-5:  48-bit timestamp in milliseconds (big-endian)
     *   Octet  6:    version (0111) in high nibble + 4 bits of rand_a
     *   Octet  7:    8 bits of rand_a
     *   Octet  8:    variant (10) in high 2 bits + 6 bits of rand_b
     *   Octets 9-15: 56 bits of rand_b
     *
     * @param int $timestampMs Unix timestamp in milliseconds.
     *
     * @return string UUID string in 8-4-4-4-12 format.
     */
    private static function uuidv7(int $timestampMs): string
    {
        $bytes = random_bytes(16);

        $bytes[0] = chr(($timestampMs >> 40) & 0xFF);
        $bytes[1] = chr(($timestampMs >> 32) & 0xFF);
        $bytes[2] = chr(($timestampMs >> 24) & 0xFF);
        $bytes[3] = chr(($timestampMs >> 16) & 0xFF);
        $bytes[4] = chr(($timestampMs >> 8) & 0xFF);
        $bytes[5] = chr($timestampMs & 0xFF);

        $bytes[6] = chr(0x70 | (ord($bytes[6]) & 0x0F));

        $bytes[8] = chr(0x80 | (ord($bytes[8]) & 0x3F));

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
