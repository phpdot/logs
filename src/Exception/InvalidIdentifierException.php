<?php

declare(strict_types=1);

/**
 * Invalid Identifier Exception
 *
 * Thrown when a trace id, span id, or W3C `traceparent` header is malformed or
 * forbidden (wrong length, non-hex, or the all-zero sentinel that W3C reserves
 * as "invalid"). An invalid identifier is always a caller/protocol error, so
 * this extends InvalidArgumentException.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Exception;

use InvalidArgumentException;

final class InvalidIdentifierException extends InvalidArgumentException
{
    /**
     * Create for an invalid trace id.
     *
     * @param string $id The offending value.
     *
     * @return self The exception instance.
     */
    public static function traceId(string $id): self
    {
        return new self("Invalid trace id: expected 32 non-zero hex characters, got '{$id}'.");
    }

    /**
     * Create for an invalid span id.
     *
     * @param string $id The offending value.
     *
     * @return self The exception instance.
     */
    public static function spanId(string $id): self
    {
        return new self("Invalid span id: expected 16 non-zero hex characters, got '{$id}'.");
    }

    /**
     * Create for an invalid W3C `traceparent` header.
     *
     * @param string $header The offending header value.
     *
     * @return self The exception instance.
     */
    public static function traceparent(string $header): self
    {
        return new self("Invalid W3C traceparent header: '{$header}'.");
    }
}
