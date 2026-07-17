<?php

declare(strict_types=1);

namespace PHPdot\Logs\Tests;

use PHPdot\Container\Context\ArrayContextProvider;
use PHPdot\Contracts\Logs\SpanInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\CoreTracer;
use PHPdot\Logs\ScopeManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class CoreTracerTest extends TestCase
{
    // ---------------------------------------------------------------------
    // channel()
    // ---------------------------------------------------------------------

    #[Test]
    public function channelReturnsADistinctClone(): void
    {
        $tracer = new CoreTracer($this->scope(), $this->writer());

        $scoped = $tracer->channel('http');

        self::assertInstanceOf(CoreTracer::class, $scoped);
        self::assertNotSame($tracer, $scoped);
    }

    #[Test]
    public function channelTagsLogRecordsWithItsName(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->channel('auth')->info('login');

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);
        self::assertSame('auth', $logs[0]['channel']);
    }

    #[Test]
    public function channelTagsSpanRecordsWithItsName(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->channel('db')->span('query', 'client')->end();

        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('db', $spans[0]['channel']);
        self::assertSame('client', $spans[0]['kind']);
    }

    #[Test]
    public function channelDoesNotMutateTheOriginalTracer(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        // Derive a channel'd tracer, then log on BOTH the channel and the original.
        $tracer->channel('http')->info('routed');
        $tracer->info('handled');

        $logs = $this->logsIn($writer->records);
        self::assertCount(2, $logs);
        self::assertSame('http', $logs[0]['channel']);
        self::assertSame('app', $logs[1]['channel']);
    }

    #[Test]
    public function channelsShareTheRequestTraceIdentity(): void
    {
        $writer = $this->writer();
        $scope  = $this->scope();
        $tracer = new CoreTracer($scope, $writer);

        // The two tracers share one ScopeManager, so they share the active span.
        $root = $tracer->span('root');
        $tracer->channel('http')->info('on http');

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);
        self::assertSame($root->context()->traceId(), $logs[0]['trace_id']);
        self::assertSame('http', $logs[0]['channel']);
    }

    // ---------------------------------------------------------------------
    // span()
    // ---------------------------------------------------------------------

    #[Test]
    public function spanActivatesTheCreatedSpanAsCurrent(): void
    {
        $scope  = $this->scope();
        $tracer = new CoreTracer($scope, $this->writer());

        $span = $tracer->span('work');

        self::assertInstanceOf(SpanInterface::class, $span);
        self::assertSame($span, $scope->current());
        self::assertSame($span, $tracer->current());
    }

    #[Test]
    public function spanRecordsTheGivenKindOnEnd(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->span('outbound', 'producer')->end();

        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('span', $spans[0]['type']);
        self::assertSame('outbound', $spans[0]['name']);
        self::assertSame('producer', $spans[0]['kind']);
    }

    #[Test]
    public function spanDefaultsToInternalKind(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->span('plain')->end();

        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('internal', $spans[0]['kind']);
    }

    #[Test]
    public function spanFallsBackToInternalForAnUnknownKind(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->span('weird', 'bogus-kind')->end();

        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('internal', $spans[0]['kind']);
    }

    #[Test]
    public function spanWithNoParentIsARoot(): void
    {
        $tracer = new CoreTracer($this->scope(), $this->writer());

        $span = $tracer->span('root');

        self::assertNull($span->context()->parentSpanId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->context()->traceId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->context()->spanId());
    }

    #[Test]
    public function spanIsCreatedAsAChildOfTheCurrentSpan(): void
    {
        $tracer = new CoreTracer($this->scope(), $this->writer());

        $parent = $tracer->span('parent');
        $child  = $tracer->span('child');

        self::assertSame($parent->context()->traceId(), $child->context()->traceId());
        self::assertSame($parent->context()->spanId(), $child->context()->parentSpanId());
        self::assertNotSame($parent->context()->spanId(), $child->context()->spanId());
    }

    // ---------------------------------------------------------------------
    // trace()
    // ---------------------------------------------------------------------

    #[Test]
    public function traceReturnsTheCallbackValueUnchanged(): void
    {
        $tracer = new CoreTracer($this->scope(), $this->writer());

        $result = $tracer->trace('compute', 'internal', static fn(): int => 42);

        self::assertSame(42, $result);
    }

    #[Test]
    public function tracePassesTheActiveSpanToTheCallback(): void
    {
        $scope  = $this->scope();
        $tracer = new CoreTracer($scope, $this->writer());

        $tracer->trace('op', 'server', function (SpanInterface $span) use ($scope): null {
            self::assertSame($span, $scope->current());

            return null;
        });
    }

    #[Test]
    public function traceRecordsTheGivenKind(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->trace('call', 'client', static fn(): string => 'done');

        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('client', $spans[0]['kind']);
        self::assertSame('call', $spans[0]['name']);
    }

    #[Test]
    public function traceAutoEndsTheSpanAndRestoresThePreviousCurrent(): void
    {
        $writer = $this->writer();
        $scope  = $this->scope();
        $tracer = new CoreTracer($scope, $writer);

        $root = $tracer->span('root');

        $tracer->trace('child', 'internal', function (SpanInterface $span) use ($tracer, $root): null {
            // Inside: the trace span is current and is a child of the root.
            self::assertNotSame($root, $tracer->current());
            self::assertSame($root->context()->spanId(), $span->context()->parentSpanId());

            return null;
        });

        // The span record proves end() ran; the root is current again.
        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('child', $spans[0]['name']);
        self::assertSame($root, $scope->current());
    }

    #[Test]
    public function traceLeavesASuccessfulSpanStatusUnset(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        // The contract only mandates marking 'error' on throw; success stays 'unset'.
        $tracer->trace('ok', 'internal', static fn(): bool => true);

        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('unset', $spans[0]['status']);
        self::assertSame('', $spans[0]['status_message']);
    }

    #[Test]
    public function traceMarksTheSpanErrorEndsItAndRethrows(): void
    {
        $writer = $this->writer();
        $scope  = $this->scope();
        $tracer = new CoreTracer($scope, $writer);

        $boom = new \RuntimeException('kaboom');

        try {
            $tracer->trace('risky', 'internal', function () use ($boom): never {
                throw $boom;
            });
            self::fail('Expected the exception to be re-thrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($boom, $caught);
        }

        // The span was still ended and exported, marked as an error.
        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertSame('risky', $spans[0]['name']);
        self::assertSame('error', $spans[0]['status']);
        self::assertSame('kaboom', $spans[0]['status_message']);
    }

    #[Test]
    public function traceSpanRecordCarriesWellFormedTimings(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->trace('timed', 'internal', static fn(): int => 1);

        $spans = $this->spansIn($writer->records);
        self::assertCount(1, $spans);
        self::assertIsFloat($spans[0]['started_at']);
        self::assertIsFloat($spans[0]['ended_at']);
        self::assertIsFloat($spans[0]['duration_ms']);
        self::assertGreaterThanOrEqual($spans[0]['started_at'], $spans[0]['ended_at']);
        self::assertGreaterThanOrEqual(0.0, $spans[0]['duration_ms']);
    }

    // ---------------------------------------------------------------------
    // current() / context()
    // ---------------------------------------------------------------------

    #[Test]
    public function currentReturnsTheActiveSpan(): void
    {
        $tracer = new CoreTracer($this->scope(), $this->writer());

        $span = $tracer->span('active');

        self::assertSame($span, $tracer->current());
    }

    #[Test]
    public function currentLazilyStartsARootSpanWhenNoneIsActive(): void
    {
        $writer = $this->writer();
        $scope  = $this->scope();
        $tracer = new CoreTracer($scope, $writer);

        $span = $tracer->current();

        self::assertInstanceOf(SpanInterface::class, $span);
        self::assertNull($span->context()->parentSpanId());
        // It was activated and is reused by the next call.
        self::assertSame($span, $scope->current());
        self::assertSame($span, $tracer->current());
        // Lazily starting a root does not export a span record.
        self::assertSame([], $this->spansIn($writer->records));
    }

    #[Test]
    public function contextReturnsTheCurrentSpanContext(): void
    {
        $tracer = new CoreTracer($this->scope(), $this->writer());

        $span = $tracer->span('x');

        self::assertSame($span->context(), $tracer->context());
    }

    #[Test]
    public function contextExposesWellFormedTraceAndSpanIds(): void
    {
        $tracer = new CoreTracer($this->scope(), $this->writer());

        $context = $tracer->context();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context->traceId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context->spanId());
    }

    // ---------------------------------------------------------------------
    // debug() / info() / warning() / error()
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function logLevelProvider(): array
    {
        return [
            'debug'   => ['debug', 'debug'],
            'info'    => ['info', 'info'],
            'warning' => ['warning', 'warning'],
            'error'   => ['error', 'error'],
        ];
    }

    #[Test]
    #[DataProvider('logLevelProvider')]
    public function logMethodWritesACorrelatedRecord(string $method, string $expectedLevel): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $span = $tracer->span('unit');
        $tracer->{$method}('the message', ['user_id' => 7]);

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);

        $log = $logs[0];
        self::assertSame('log', $log['type']);
        self::assertSame($expectedLevel, $log['level']);
        self::assertSame('the message', $log['message']);
        self::assertSame(['user_id' => 7], $log['context']);
        self::assertSame('app', $log['channel']);
        self::assertSame($span->context()->traceId(), $log['trace_id']);
        self::assertSame($span->context()->spanId(), $log['span_id']);
        self::assertIsFloat($log['timestamp']);
    }

    #[Test]
    public function logContextDefaultsToAnEmptyArray(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->span('unit');
        $tracer->info('no context');

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);
        self::assertSame([], $logs[0]['context']);
    }

    #[Test]
    public function logsCorrelateToALazyRootWhenNoSpanIsActive(): void
    {
        $writer = $this->writer();
        $scope  = $this->scope();
        $tracer = new CoreTracer($scope, $writer);

        $tracer->warning('early line');

        // The log triggered the lazy root; it carries that root's identity.
        $root = $scope->current();
        self::assertInstanceOf(SpanInterface::class, $root);
        self::assertNull($root->context()->parentSpanId());

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0]['level']);
        self::assertSame($root->context()->traceId(), $logs[0]['trace_id']);
        self::assertSame($root->context()->spanId(), $logs[0]['span_id']);
    }

    #[Test]
    public function logUsesTheTracerChannel(): void
    {
        $writer = $this->writer();
        $tracer = (new CoreTracer($this->scope(), $writer))->channel('payments');

        $tracer->error('charge failed');

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);
        self::assertSame('payments', $logs[0]['channel']);
        self::assertSame('error', $logs[0]['level']);
    }

    // ---------------------------------------------------------------------
    // secure() — per-record sensitive marking (encrypted by the backend)
    // ---------------------------------------------------------------------

    #[Test]
    public function secureMarksTheLogRecordSoTheBackendEncryptsIt(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->error('SSN 123-45-6789', ['card' => '4111'])->secure();

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);
        self::assertTrue($logs[0]['secure']);
    }

    #[Test]
    public function aLogWithoutSecureCarriesNoSecureFlag(): void
    {
        $writer = $this->writer();
        $tracer = new CoreTracer($this->scope(), $writer);

        $tracer->info('GET /orders', ['status' => 200]);

        $logs = $this->logsIn($writer->records);
        self::assertCount(1, $logs);
        self::assertArrayNotHasKey('secure', $logs[0]);
    }

    // ---------------------------------------------------------------------
    // Fixtures — real engine collaborators + an inline capturing writer.
    // ---------------------------------------------------------------------

    /**
     * A real per-coroutine scope manager backed by an array context, exactly as
     * the engine wires it.
     */
    private function scope(): ScopeManager
    {
        return new ScopeManager(new ArrayContextProvider());
    }

    /**
     * An inline capturing writer: every record the tracer/span exports lands in
     * its public `$records` array for assertion.
     *
     * @return WriterInterface&object{records: list<array<string, mixed>>}
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

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private function logsIn(array $records): array
    {
        return array_values(array_filter(
            $records,
            static fn(array $record): bool => $record['type'] === 'log',
        ));
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private function spansIn(array $records): array
    {
        return array_values(array_filter(
            $records,
            static fn(array $record): bool => $record['type'] === 'span',
        ));
    }
}
