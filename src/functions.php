<?php

declare(strict_types=1);

/**
 * Shared logging vocabulary: global helper functions for the observability seam.
 *
 * Defined in the global namespace — like env() — so call sites need no import.
 * Guarded by function_exists: in the unlikely co-existence with another global
 * _e() (e.g. WordPress), the first definition wins.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

if (!function_exists('_e')) {
    /**
     * The canonical context shape for an exception.
     *
     * Produces the exact `exception` structure the logging standard requires,
     * so call sites never hand-build it and the shape cannot drift between
     * packages: `$tracer->error('...', ['exception' => _e($ex)])`.
     *
     * @param Throwable $exception The throwable to encode.
     *
     * @return array{
     *     class: class-string<Throwable>,
     *     message: string,
     *     code: int|string,
     *     file: string,
     *     line: int
     * }
     */
    function _e(Throwable $exception): array
    {
        return [
            'class'   => $exception::class,
            'message' => $exception->getMessage(),
            'code'    => $exception->getCode(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ];
    }
}
