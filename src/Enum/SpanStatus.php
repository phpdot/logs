<?php

declare(strict_types=1);

/**
 * Span Status
 *
 * The explicit outcome recorded on a span. The backing string values are the
 * exact tokens accepted by
 * `PHPdot\Contracts\Logs\SpanInterface::setStatus()`.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Enum;

enum SpanStatus: string
{
    /** No outcome recorded yet — the default for every new span. */
    case Unset = 'unset';

    /** The operation completed successfully. */
    case Ok = 'ok';

    /** The operation failed. */
    case Error = 'error';

    /**
     * Resolve a status token to a case, tolerating case and falling back to
     * Unset for unknown values (the default span outcome).
     *
     * @param string $status The status token (e.g. 'ok', 'ERROR').
     *
     * @return self The matching case, or Unset when unrecognised.
     */
    public static function fromString(string $status): self
    {
        return self::tryFrom(strtolower($status)) ?? self::Unset;
    }
}
