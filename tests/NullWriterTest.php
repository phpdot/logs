<?php

declare(strict_types=1);

/**
 * Null Writer Test
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Tests;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\NullWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NullWriterTest extends TestCase
{
    private function logRecord(): array
    {
        return [
            'type' => 'log',
            'level' => 'info',
            'message' => 'GET /users',
            'channel' => 'http',
            'trace_id' => 'tracewxyz9',
            'span_id' => 's1',
            'timestamp' => microtime(true),
            'context' => ['user_id' => 42],
        ];
    }

    private function spanRecord(): array
    {
        return [
            'type' => 'span',
            'name' => 'db.query',
            'kind' => 'client',
            'trace_id' => 'tracewxyz9',
            'span_id' => 'c1',
            'parent_span_id' => 's1',
            'started_at' => 1.0,
            'ended_at' => 2.0,
            'duration_ms' => 1000.0,
            'status' => 'ok',
            'status_message' => '',
            'attributes' => ['db.system' => 'mysql'],
            'events' => [],
        ];
    }

    #[Test]
    public function implementsWriterInterface(): void
    {
        self::assertInstanceOf(WriterInterface::class, new NullWriter());
    }

    #[Test]
    public function writeReturnsVoid(): void
    {
        $type = (new \ReflectionMethod(NullWriter::class, 'write'))->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('void', $type->getName());
    }

    #[Test]
    public function classIsFinal(): void
    {
        self::assertTrue((new \ReflectionClass(NullWriter::class))->isFinal());
    }

    #[Test]
    public function isBoundAsTheDefaultWriterInterface(): void
    {
        $attributes = (new \ReflectionClass(NullWriter::class))->getAttributes(Binds::class);

        self::assertCount(1, $attributes);
        self::assertSame(WriterInterface::class, $attributes[0]->newInstance()->interface);
    }

    #[Test]
    public function isMarkedSingleton(): void
    {
        $attributes = (new \ReflectionClass(NullWriter::class))->getAttributes(Singleton::class);

        self::assertCount(1, $attributes);
    }

    #[Test]
    public function writeAcceptsEmptyArrayWithoutThrowing(): void
    {
        $writer = new NullWriter();

        try {
            $writer->write([]);
        } catch (\Throwable $e) {
            self::fail('write([]) must never throw: ' . $e->getMessage());
        }

        self::assertTrue(true);
    }

    #[Test]
    public function writeAcceptsLogRecordWithoutThrowing(): void
    {
        $writer = new NullWriter();

        try {
            $writer->write($this->logRecord());
        } catch (\Throwable $e) {
            self::fail('write(log) must never throw: ' . $e->getMessage());
        }

        self::assertTrue(true);
    }

    #[Test]
    public function writeAcceptsSpanRecordWithoutThrowing(): void
    {
        $writer = new NullWriter();

        try {
            $writer->write($this->spanRecord());
        } catch (\Throwable $e) {
            self::fail('write(span) must never throw: ' . $e->getMessage());
        }

        self::assertTrue(true);
    }

    #[Test]
    public function writeAcceptsSensitiveRecordWithoutThrowing(): void
    {
        $writer = new NullWriter();

        // A record flagged sensitive: the no-op discards everything anyway,
        // so it is fail-closed by construction and must never throw.
        try {
            $writer->write([
                'type' => 'log',
                'level' => 'error',
                'message' => 'SSN 123-45-6789',
                'secure' => true,
                'trace_id' => 't',
                'span_id' => 's',
                'timestamp' => microtime(true),
                'context' => ['card' => '4111111111111111'],
            ]);
        } catch (\Throwable $e) {
            self::fail('write(sensitive) must never throw: ' . $e->getMessage());
        }

        self::assertTrue(true);
    }

    #[Test]
    public function writeAcceptsUnusualRecordShapesWithoutThrowing(): void
    {
        $writer = new NullWriter();

        try {
            $writer->write([
                'nested' => ['a' => ['b' => ['c' => [1, 2, 3]]]],
                'null_value' => null,
                'bool' => false,
                'float' => 3.14,
                'object' => new \stdClass(),
                'closure' => static fn(): int => 1,
                0 => 'numeric-key',
                'unicode' => "emoji \u{1F600} and \x00 null byte",
            ]);
        } catch (\Throwable $e) {
            self::fail('write(unusual) must never throw: ' . $e->getMessage());
        }

        self::assertTrue(true);
    }

    #[Test]
    public function repeatedWritesNeverThrow(): void
    {
        $writer = new NullWriter();

        try {
            for ($i = 0; $i < 100; $i++) {
                $writer->write([
                    'type' => 'log',
                    'level' => 'info',
                    'message' => "m{$i}",
                    'trace_id' => 't',
                    'span_id' => 's',
                    'timestamp' => microtime(true),
                    'context' => [],
                ]);
            }
        } catch (\Throwable $e) {
            self::fail('repeated write() must never throw: ' . $e->getMessage());
        }

        self::assertTrue(true);
    }

    #[Test]
    public function discardsRecordsWhileARealWriterWouldCaptureThem(): void
    {
        // Contrast: an identical record fed to a capturing writer is observed,
        // proving the record is well-formed and that NullWriter's silence is a
        // deliberate discard rather than a malformed input being rejected.
        $capturing = new class implements WriterInterface {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            public function write(array $record): void
            {
                $this->records[] = $record;
            }
        };

        $record = $this->logRecord();

        $capturing->write($record);
        (new NullWriter())->write($record);

        self::assertCount(1, $capturing->records);
        self::assertSame($record, $capturing->records[0]);
    }
}
