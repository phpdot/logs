<?php

declare(strict_types=1);

/**
 * Span Kind
 *
 * The role a span plays in a trace, per the OpenTelemetry span-kind model. The
 * backing string values are the exact tokens accepted by
 * `PHPdot\Contracts\Logs\TracerInterface::span()` and `trace()`.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Enum;

enum SpanKind: string
{
    /** Work that is neither a remote boundary nor a messaging operation. */
    case Internal = 'internal';

    /** The server side of a synchronous remote call (inbound request handler). */
    case Server = 'server';

    /** The client side of a synchronous remote call (outbound request). */
    case Client = 'client';

    /** The producer side of an asynchronous messaging operation. */
    case Producer = 'producer';

    /** The consumer side of an asynchronous messaging operation. */
    case Consumer = 'consumer';

    /**
     * Resolve a kind token to a case, tolerating case and falling back to
     * Internal for unknown values (matching the contract's default kind).
     *
     * @param string $kind The kind token (e.g. 'server', 'CLIENT').
     *
     * @return self The matching case, or Internal when unrecognised.
     */
    public static function fromString(string $kind): self
    {
        return self::tryFrom(strtolower($kind)) ?? self::Internal;
    }
}
