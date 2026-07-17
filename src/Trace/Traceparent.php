<?php

declare(strict_types=1);

/**
 * Traceparent
 *
 * Immutable value object for the W3C Trace Context `traceparent` header plus its
 * companion `tracestate`. It is the single low-level codec for inbound
 * propagation: `parse()` decodes and validates a header (version, trace id, span
 * id, and trace-flags byte), and `toHeader()` re-emits it in the canonical
 * version-`00` form. `SpanContext` is the contract-facing wrapper around this
 * helper.
 *
 * Header grammar (version 00): `00-<32 hex trace-id>-<16 hex span-id>-<2 hex flags>`.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Trace;

use PHPdot\Logs\Exception\InvalidIdentifierException;

final class Traceparent
{
    /** The W3C "invalid" all-zero trace id. */
    private const string ZERO_TRACE = '00000000000000000000000000000000';

    /** The W3C "invalid" all-zero span id. */
    private const string ZERO_SPAN = '0000000000000000';

    /** Bit 0 of the trace-flags byte: the "sampled" (recorded) flag. */
    private const int FLAG_SAMPLED = 0x01;

    /**
     * Create a W3C traceparent value from its parts.
     *
     * @param string $traceId 32 lowercase hex characters.
     * @param string $spanId 16 lowercase hex characters.
     * @param int $flags The trace-flags byte (0-255).
     * @param string $traceState The companion `tracestate` header value (may be empty).
     */
    private function __construct(
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly int $flags,
        private readonly string $traceState,
    ) {}

    /**
     * Decode and validate a W3C `traceparent` header (with optional `tracestate`).
     *
     * Rejects malformed structure, an unknown/`ff` version, a non-hex flags byte,
     * and the all-zero "invalid" trace id or span id.
     *
     * @param string $header The `traceparent` header value.
     * @param string $traceState The companion `tracestate` header value, if present.
     *
     * @throws InvalidIdentifierException If the header is malformed or carries an invalid id.
     *
     * @return self The decoded traceparent.
     */
    public static function parse(string $header, string $traceState = ''): self
    {
        $parts = explode('-', strtolower(trim($header)));

        if (count($parts) !== 4) {
            throw InvalidIdentifierException::traceparent($header);
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if (preg_match('/^[0-9a-f]{2}$/', $version) !== 1 || $version === 'ff') {
            throw InvalidIdentifierException::traceparent($header);
        }

        if (preg_match('/^[0-9a-f]{2}$/', $flags) !== 1) {
            throw InvalidIdentifierException::traceparent($header);
        }

        if (preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1 || $traceId === self::ZERO_TRACE) {
            throw InvalidIdentifierException::traceId($traceId);
        }

        if (preg_match('/^[0-9a-f]{16}$/', $spanId) !== 1 || $spanId === self::ZERO_SPAN) {
            throw InvalidIdentifierException::spanId($spanId);
        }

        return new self($traceId, $spanId, (int) hexdec($flags), trim($traceState));
    }

    /**
     * The trace id (32 lowercase hex characters).
     *
     * @return string 32 hex characters.
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * The span id (16 lowercase hex characters).
     *
     * @return string 16 hex characters.
     */
    public function spanId(): string
    {
        return $this->spanId;
    }

    /**
     * The raw trace-flags byte (0-255).
     *
     * @return int The flags byte.
     */
    public function flags(): int
    {
        return $this->flags;
    }

    /**
     * Whether the W3C sampled flag (bit 0 of trace-flags) is set.
     *
     * @return bool True when the trace is recorded.
     */
    public function sampled(): bool
    {
        return ($this->flags & self::FLAG_SAMPLED) === self::FLAG_SAMPLED;
    }

    /**
     * The companion `tracestate` header value (empty when absent).
     *
     * @return string The vendor `tracestate` list.
     */
    public function traceState(): string
    {
        return $this->traceState;
    }

    /**
     * Emit the canonical version-`00` `traceparent` header value.
     *
     * @return string The `traceparent` header.
     */
    public function toHeader(): string
    {
        return sprintf('00-%s-%s-%02x', $this->traceId, $this->spanId, $this->flags & 0xFF);
    }

    /**
     * String representation returns the W3C header form.
     *
     * @return string The `traceparent` header.
     */
    public function __toString(): string
    {
        return $this->toHeader();
    }
}
