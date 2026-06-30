<?php

declare(strict_types=1);

/**
 * Core Span Test
 *
 * Exercises the engine span: fluent mutators, identity read-back, correlated log
 * emission, and the single span record exported on end().
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Tests;

use PHPdot\Contracts\Logs\PendingLogInterface;
use PHPdot\Contracts\Logs\ScopeManagerInterface;
use PHPdot\Contracts\Logs\SpanContextInterface;
use PHPdot\Contracts\Logs\SpanInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\CoreSpan;
use PHPdot\Logs\Enum\SpanKind;
use PHPdot\Logs\Enum\SpanStatus;
use PHPdot\Logs\Trace\SpanContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoreSpanTest extends TestCase
{
    /**
     * A writer that captures every exported record into a public array.
     *
     * Returned as an anonymous class so it has no FQN to collide with other
     * parallel test runs; the declared `WriterInterface` return type does not
     * restrict runtime access to the public `$records` property.
     */
    private function capturingWriter(): WriterInterface
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

    /**
     * A scope manager that records which spans it was asked to deactivate.
     */
    private function recordingScope(): ScopeManagerInterface
    {
        return new class implements ScopeManagerInterface {
            /** @var list<SpanInterface> */
            public array $deactivated = [];

            public ?SpanInterface $currentSpan = null;

            public function current(): ?SpanInterface
            {
                return $this->currentSpan;
            }

            public function activate(SpanInterface $span): void
            {
                $this->currentSpan = $span;
            }

            public function deactivate(SpanInterface $span): void
            {
                $this->deactivated[] = $span;
            }

            public function close(): void {}
        };
    }

    private function makeSpan(
        WriterInterface $writer,
        ScopeManagerInterface $scope,
        SpanKind $kind = SpanKind::Internal,
        string $channel = 'app',
        ?SpanContextInterface $context = null,
        string $name = 'test.span',
    ): CoreSpan {
        return new CoreSpan(
            $context ?? SpanContext::root(),
            $name,
            $kind,
            $writer,
            $scope,
            $channel,
        );
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private function ofType(array $records, string $type): array
    {
        return array_values(array_filter($records, static fn(array $r): bool => ($r['type'] ?? null) === $type));
    }

    // ---------------------------------------------------------------------
    // Fluent mutators
    // ---------------------------------------------------------------------

    #[Test]
    public function setAttributeReturnsSameInstance(): void
    {
        $span = $this->makeSpan($this->capturingWriter(), $this->recordingScope());

        self::assertSame($span, $span->setAttribute('key', 'value'));
    }

    #[Test]
    public function addEventReturnsSameInstance(): void
    {
        $span = $this->makeSpan($this->capturingWriter(), $this->recordingScope());

        self::assertSame($span, $span->addEvent('event'));
    }

    #[Test]
    public function setStatusReturnsSameInstance(): void
    {
        $span = $this->makeSpan($this->capturingWriter(), $this->recordingScope());

        self::assertSame($span, $span->setStatus('ok'));
    }

    #[Test]
    public function logMethodsReturnAPendingLogHandle(): void
    {
        $span = $this->makeSpan($this->capturingWriter(), $this->recordingScope());

        self::assertInstanceOf(PendingLogInterface::class, $span->debug('d'));
        self::assertInstanceOf(PendingLogInterface::class, $span->info('i'));
        self::assertInstanceOf(PendingLogInterface::class, $span->warning('w'));
        self::assertInstanceOf(PendingLogInterface::class, $span->error('e'));
    }

    #[Test]
    public function mutatorsChainOnTheSameInstance(): void
    {
        $span = $this->makeSpan($this->capturingWriter(), $this->recordingScope());

        $result = $span
            ->setAttribute('a', 1)
            ->addEvent('e')
            ->setStatus('ok');

        self::assertSame($span, $result);
    }

    // ---------------------------------------------------------------------
    // context()
    // ---------------------------------------------------------------------

    #[Test]
    public function contextReturnsTheInjectedContextInstance(): void
    {
        $context = SpanContext::root();
        $span    = $this->makeSpan($this->capturingWriter(), $this->recordingScope(), context: $context);

        self::assertSame($context, $span->context());
    }

    // ---------------------------------------------------------------------
    // Log emission
    // ---------------------------------------------------------------------

    #[Test]
    public function logLevelsEmitCorrelatedLogRecords(): void
    {
        $writer  = $this->capturingWriter();
        $context = SpanContext::root();
        $span    = $this->makeSpan($writer, $this->recordingScope(), channel: 'auth', context: $context);

        foreach (['debug', 'info', 'warning', 'error'] as $i => $level) {
            $span->{$level}("message-{$level}", ['idx' => $i]);
        }

        self::assertCount(4, $writer->records);

        foreach (['debug', 'info', 'warning', 'error'] as $i => $level) {
            $record = $writer->records[$i];

            self::assertSame('log', $record['type']);
            self::assertSame($level, $record['level']);
            self::assertSame("message-{$level}", $record['message']);
            self::assertSame('auth', $record['channel']);
            self::assertSame($context->traceId(), $record['trace_id']);
            self::assertSame($context->spanId(), $record['span_id']);
            self::assertSame(['idx' => $i], $record['context']);
            self::assertIsFloat($record['timestamp']);
        }
    }

    #[Test]
    public function logContextDefaultsToEmptyArray(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->info('no context');

        self::assertSame([], $writer->records[0]['context']);
    }

    #[Test]
    public function logRecordCarriesTheExactRecordShape(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->debug('shape check', ['k' => 'v']);

        self::assertSame(
            ['type', 'level', 'message', 'channel', 'trace_id', 'span_id', 'timestamp', 'context'],
            array_keys($writer->records[0]),
        );
    }

    #[Test]
    public function logsBeforeEndDoNotEmitSpanRecords(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->info('one');
        $span->warning('two');

        self::assertCount(2, $writer->records);
        self::assertCount(0, $this->ofType($writer->records, 'span'));
        self::assertCount(2, $this->ofType($writer->records, 'log'));
    }

    // ---------------------------------------------------------------------
    // end() — span record
    // ---------------------------------------------------------------------

    #[Test]
    public function endEmitsExactlyOneSpanRecord(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->end();

        self::assertCount(1, $this->ofType($writer->records, 'span'));
    }

    #[Test]
    public function endSpanRecordCarriesTheFullShape(): void
    {
        $writer  = $this->capturingWriter();
        $context = SpanContext::root();
        $span    = $this->makeSpan(
            $writer,
            $this->recordingScope(),
            kind: SpanKind::Client,
            channel: 'db',
            context: $context,
            name: 'db.query',
        );

        $span->setAttribute('db.system', 'mysql');
        $span->addEvent('queried');
        $span->setStatus('ok');
        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertSame(
            [
                'type', 'name', 'kind', 'channel', 'trace_id', 'span_id', 'parent_span_id',
                'started_at', 'ended_at', 'duration_ms', 'status', 'status_message', 'attributes', 'events',
            ],
            array_keys($record),
        );

        self::assertSame('span', $record['type']);
        self::assertSame('db.query', $record['name']);
        self::assertSame(SpanKind::Client->value, $record['kind']);
        self::assertSame('client', $record['kind']);
        self::assertSame('db', $record['channel']);
        self::assertSame($context->traceId(), $record['trace_id']);
        self::assertSame($context->spanId(), $record['span_id']);
        self::assertSame($context->parentSpanId(), $record['parent_span_id']);
        self::assertSame('ok', $record['status']);
        self::assertSame(['db.system' => 'mysql'], $record['attributes']);
    }

    #[Test]
    public function endSpanRecordHasMonotonicFloatTimestamps(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertIsFloat($record['started_at']);
        self::assertIsFloat($record['ended_at']);
        self::assertIsFloat($record['duration_ms']);
        self::assertGreaterThanOrEqual($record['started_at'], $record['ended_at']);
        self::assertGreaterThanOrEqual(0.0, $record['duration_ms']);
        self::assertEqualsWithDelta(
            ($record['ended_at'] - $record['started_at']) * 1000.0,
            $record['duration_ms'],
            0.0001,
        );
    }

    #[Test]
    public function endDefaultsToUnsetStatusAndEmptyMessage(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertSame(SpanStatus::Unset->value, $record['status']);
        self::assertSame('unset', $record['status']);
        self::assertSame('', $record['status_message']);
    }

    #[Test]
    public function endHasEmptyAttributesAndEventsByDefault(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertSame([], $record['attributes']);
        self::assertSame([], $record['events']);
    }

    #[Test]
    public function endChannelDefaultsToApp(): void
    {
        $writer = $this->capturingWriter();
        $span   = new CoreSpan(
            SpanContext::root(),
            'no.channel',
            SpanKind::Internal,
            $writer,
            $this->recordingScope(),
        );

        $span->end();

        self::assertSame('app', $this->ofType($writer->records, 'span')[0]['channel']);
    }

    #[Test]
    public function endDeactivatesItselfFromTheScope(): void
    {
        $scope = $this->recordingScope();
        $span  = $this->makeSpan($this->capturingWriter(), $scope);

        $span->end();

        self::assertCount(1, $scope->deactivated);
        self::assertSame($span, $scope->deactivated[0]);
    }

    #[Test]
    public function endIsIdempotent(): void
    {
        $writer = $this->capturingWriter();
        $scope  = $this->recordingScope();
        $span   = $this->makeSpan($writer, $scope);

        $span->end();
        $span->end();
        $span->end();

        self::assertCount(1, $this->ofType($writer->records, 'span'));
        self::assertCount(1, $scope->deactivated);
    }

    #[Test]
    public function logsAfterEndAreStillEmittedButSpanIsExportedOnce(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->info('before');
        $span->end();
        $span->info('after');

        self::assertCount(1, $this->ofType($writer->records, 'span'));
        self::assertCount(2, $this->ofType($writer->records, 'log'));
    }

    // ---------------------------------------------------------------------
    // Accumulation
    // ---------------------------------------------------------------------

    #[Test]
    public function attributesAccumulateAcrossCallsAndLastWriteWins(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->setAttribute('one', 1);
        $span->setAttribute('two', 'second');
        $span->setAttribute('flag', true);
        $span->setAttribute('ratio', 1.5);
        $span->setAttribute('one', 99); // overwrite
        $span->end();

        $attributes = $this->ofType($writer->records, 'span')[0]['attributes'];

        self::assertSame(
            ['one' => 99, 'two' => 'second', 'flag' => true, 'ratio' => 1.5],
            $attributes,
        );
    }

    #[Test]
    public function eventsAccumulateInOrderWithTimestampsAndAttributes(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->addEvent('first');
        $span->addEvent('second', ['detail' => 'x', 'count' => 3]);
        $span->end();

        $events = $this->ofType($writer->records, 'span')[0]['events'];

        self::assertCount(2, $events);

        self::assertSame('first', $events[0]['name']);
        self::assertSame([], $events[0]['attributes']);
        self::assertIsFloat($events[0]['timestamp']);

        self::assertSame('second', $events[1]['name']);
        self::assertSame(['detail' => 'x', 'count' => 3], $events[1]['attributes']);
        self::assertIsFloat($events[1]['timestamp']);

        self::assertSame(
            ['name', 'timestamp', 'attributes'],
            array_keys($events[0]),
        );
    }

    // ---------------------------------------------------------------------
    // setStatus resolution
    // ---------------------------------------------------------------------

    #[Test]
    public function setStatusRecordsErrorWithDescription(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->setStatus('error', 'boom');
        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertSame('error', $record['status']);
        self::assertSame('boom', $record['status_message']);
    }

    #[Test]
    public function setStatusToleratesCaseInTheStatusToken(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->setStatus('ERROR');
        $span->end();

        self::assertSame('error', $this->ofType($writer->records, 'span')[0]['status']);
    }

    #[Test]
    public function setStatusFallsBackToUnsetForUnknownToken(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->setStatus('totally-bogus', 'note');
        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertSame('unset', $record['status']);
        self::assertSame('note', $record['status_message']);
    }

    #[Test]
    public function lastStatusWins(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope());

        $span->setStatus('error', 'first');
        $span->setStatus('ok', 'second');
        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertSame('ok', $record['status']);
        self::assertSame('second', $record['status_message']);
    }

    // ---------------------------------------------------------------------
    // kind & parent identity
    // ---------------------------------------------------------------------

    #[Test]
    public function spanKindIsExportedAsItsStringValue(): void
    {
        foreach (SpanKind::cases() as $kind) {
            $writer = $this->capturingWriter();
            $span   = $this->makeSpan($writer, $this->recordingScope(), kind: $kind);

            $span->end();

            self::assertSame($kind->value, $this->ofType($writer->records, 'span')[0]['kind']);
        }
    }

    #[Test]
    public function rootSpanHasNullParentSpanId(): void
    {
        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope(), context: SpanContext::root());

        $span->end();

        self::assertNull($this->ofType($writer->records, 'span')[0]['parent_span_id']);
    }

    #[Test]
    public function childSpanCarriesParentSpanId(): void
    {
        $parent = SpanContext::root();
        $child  = SpanContext::childOf($parent);

        $writer = $this->capturingWriter();
        $span   = $this->makeSpan($writer, $this->recordingScope(), context: $child);

        $span->end();

        $record = $this->ofType($writer->records, 'span')[0];

        self::assertSame($parent->spanId(), $record['parent_span_id']);
        self::assertSame($parent->traceId(), $record['trace_id']);
        self::assertSame($child->spanId(), $record['span_id']);
    }

    #[Test]
    public function logsAndSpanShareTheSameTraceIdentity(): void
    {
        $writer  = $this->capturingWriter();
        $context = SpanContext::root();
        $span    = $this->makeSpan($writer, $this->recordingScope(), context: $context);

        $span->info('correlated');
        $span->end();

        $log  = $this->ofType($writer->records, 'log')[0];
        $spanRecord = $this->ofType($writer->records, 'span')[0];

        self::assertSame($log['trace_id'], $spanRecord['trace_id']);
        self::assertSame($log['span_id'], $spanRecord['span_id']);
        self::assertSame($context->traceId(), $log['trace_id']);
    }
}
