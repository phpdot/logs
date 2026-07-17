<?php

declare(strict_types=1);

/**
 * Pending Log Test
 *
 * Exercises the deferred log handle: the record is written when the handle is
 * released, secure() flags it first, and it is written exactly once.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Tests;

use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\PendingLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PendingLogTest extends TestCase
{
    #[Test]
    public function theRecordIsDeferredUntilTheHandleIsReleased(): void
    {
        $writer  = $this->writer();
        $pending = new PendingLog($writer, ['type' => 'log', 'message' => 'hi']);

        self::assertSame([], $writer->records, 'nothing is written while the handle is held');

        unset($pending);

        self::assertCount(1, $writer->records);
        self::assertSame('hi', $writer->records[0]['message']);
    }

    #[Test]
    public function secureFlagsTheRecordBeforeItIsWritten(): void
    {
        $writer = $this->writer();

        // One-liner: the temporary handle is released at the end of the statement.
        (new PendingLog($writer, ['type' => 'log', 'message' => 'ssn']))->secure();

        self::assertCount(1, $writer->records);
        self::assertTrue($writer->records[0]['secure']);
    }

    #[Test]
    public function aPlainRecordCarriesNoSecureFlag(): void
    {
        $writer  = $this->writer();
        $pending = new PendingLog($writer, ['type' => 'log', 'message' => 'plain']);

        unset($pending);

        self::assertArrayNotHasKey('secure', $writer->records[0]);
    }

    #[Test]
    public function secureReturnsTheSameHandleForChaining(): void
    {
        $pending = new PendingLog($this->writer(), ['type' => 'log', 'message' => 'x']);

        self::assertSame($pending, $pending->secure());
    }

    /**
     * A writer that captures every exported record for assertions.
     */
    private function writer(): WriterInterface
    {
        return new class implements WriterInterface {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            public function write(array $record): void
            {
                $this->records[] = $record;
            }
        };
    }
}
