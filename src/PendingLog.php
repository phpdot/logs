<?php

declare(strict_types=1);

/**
 * Pending Log
 *
 * The deferred log record returned by the tracer/span log methods. It carries an
 * already-correlated record (built with the current trace identity at the call
 * site) and writes it to the {@see WriterInterface} when the handle is released —
 * its destructor — so the common one-line form flushes at the end of the
 * statement. {@see secure()} flags the record before that write so the backend
 * encrypts it. The record is written exactly once.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs;

use PHPdot\Contracts\Logs\PendingLogInterface;
use PHPdot\Contracts\Logs\WriterInterface;

final class PendingLog implements PendingLogInterface
{
    private bool $written = false;

    /**
     * Create the deferred handle; the record writes on release.
     *
     * @param WriterInterface $writer The backend the record is flushed to.
     * @param array<string, mixed> $record The correlated log record to write.
     */
    public function __construct(
        private readonly WriterInterface $writer,
        private array $record,
    ) {}

    /**
     * Secure.
     *
     * @return static
     */
    public function secure(): static
    {
        $this->record['secure'] = true;

        return $this;
    }

    /**
     * Flush the record on release — exactly once.
     */
    public function __destruct()
    {
        if ($this->written) {
            return;
        }

        $this->written = true;
        $this->writer->write($this->record);
    }
}
