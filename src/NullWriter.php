<?php

declare(strict_types=1);

/**
 * Null Writer
 *
 * The no-op writer and the default `WriterInterface` binding: every record is
 * discarded. With it bound, the engine runs end-to-end with zero configuration
 * and zero output — "tracing on, output off" — so a package can depend on the
 * tracer without any backend installed. Real backends (tracelog, psr-bridge)
 * override this binding.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Logs\WriterInterface;

#[Singleton]
#[Binds(WriterInterface::class)]
final class NullWriter implements WriterInterface
{
    /**
     * Discard the record.
     *
     * @param array<string, mixed> $record The enriched log or span record.
     *
     * @return void
     */
    public function write(array $record): void {}
}
