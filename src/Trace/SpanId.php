<?php

declare(strict_types=1);

/**
 * Span Identifier
 *
 * A 64-bit W3C Trace Context `span-id`, rendered as 16 lowercase hex characters.
 *
 * Generation is pure CSPRNG entropy (`random_bytes`). The legacy
 * timestamp+counter scheme is deliberately gone: its process-global static state
 * and millisecond busy-wait were unsafe under Swoole — statics are shared across
 * every coroutine on a worker (data race on the counter) and a busy-wait blocks
 * the entire worker. 64 bits of randomness is collision-safe for span ids and
 * needs no shared state, so it is coroutine-safe by construction. The all-zero
 * id is the W3C "invalid" sentinel and is never produced.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Trace;

use PHPdot\Logs\Exception\InvalidIdentifierException;

final class SpanId
{
    private const string ZERO = '0000000000000000';

    /**
     * Create a span id from its 16-char lowercase hex form.
     *
     * @param string $hex 16-character lowercase hex string.
     */
    private function __construct(
        private readonly string $hex,
    ) {}

    /**
     * Generate a new span identifier from 8 bytes of CSPRNG entropy.
     *
     * Coroutine-safe: holds no shared state and never busy-waits. The all-zero
     * sentinel is rejected and re-rolled (probability ~2^-64).
     *
     * @return self A fresh span id.
     */
    public static function generate(): self
    {
        do {
            $hex = bin2hex(random_bytes(8));
        } while ($hex === self::ZERO);

        return new self($hex);
    }

    /**
     * Parse a span id from a 16-character hex string.
     *
     * The all-zero id is the W3C "invalid" sentinel and is rejected.
     *
     * @param string $hex 16 hex characters.
     *
     * @throws InvalidIdentifierException If the format is invalid or all-zero.
     *
     * @return self The parsed span id.
     */
    public static function fromString(string $hex): self
    {
        $hex = strtolower($hex);

        if (preg_match('/^[0-9a-f]{16}\z/', $hex) !== 1 || $hex === self::ZERO) {
            throw InvalidIdentifierException::spanId($hex);
        }

        return new self($hex);
    }

    /**
     * Get the span id as a 16-character lowercase hex string.
     *
     * @return string 16 hex characters.
     */
    public function id(): string
    {
        return $this->hex;
    }

    /**
     * String representation returns the hex string.
     *
     * @return string 16 hex characters.
     */
    public function __toString(): string
    {
        return $this->hex;
    }
}
