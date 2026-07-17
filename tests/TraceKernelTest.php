<?php

declare(strict_types=1);

/**
 * Trace Kernel Test
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Tests;

use PHPdot\Container\Context\ArrayContextProvider;
use PHPdot\Contracts\Logs\SpanInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\CoreSpan;
use PHPdot\Logs\Enum\SpanKind;
use PHPdot\Logs\ScopeManager;
use PHPdot\Logs\Trace\SpanContext;
use PHPdot\Logs\TraceKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TraceKernelTest extends TestCase
{
    /** Canonical W3C Trace Context example trace id (32 lowercase hex). */
    private const string TRACE = '0af7651916cd43dd8448eb211c80319c';

    /** Canonical W3C Trace Context example span id (16 lowercase hex). */
    private const string SPAN = 'b7ad6b7169203331';

    // ----------------------------------------------------------------------
    // Test doubles — an inline writer that captures every exported record.
    // ----------------------------------------------------------------------

    /**
     * A fresh capturing writer. Each record exported by the engine lands in its
     * public `$records` array so a test can assert on the exact record shape.
     */
    private function writer(): WriterInterface
    {
        return new class implements WriterInterface {
            /** @var list<array<string, mixed>> Every record handed to the writer, in order. */
            public array $records = [];

            public function write(array $record): void
            {
                $this->records[] = $record;
            }
        };
    }

    /**
     * A real per-coroutine scope backed by a single in-memory array context,
     * exactly how the engine wires {@see ScopeManager} in production.
     */
    private function scope(): ScopeManager
    {
        return new ScopeManager(new ArrayContextProvider());
    }

    // ----------------------------------------------------------------------
    // handle() — happy path
    // ----------------------------------------------------------------------

    #[Test]
    public function handleRunsTheWorkCallback(): void
    {
        $kernel = new TraceKernel($this->scope(), $this->writer());

        $ran = false;
        $kernel->handle('GET /users', function () use (&$ran): string {
            $ran = true;

            return 'done';
        });

        self::assertTrue($ran);
    }

    #[Test]
    public function handleReturnsTheWorkCallbacksReturnValue(): void
    {
        $kernel = new TraceKernel($this->scope(), $this->writer());

        self::assertSame('the-result', $kernel->handle('op', static fn(): string => 'the-result'));
    }

    #[Test]
    public function handleReturnsTheSameObjectInstanceWorkReturns(): void
    {
        $kernel = new TraceKernel($this->scope(), $this->writer());

        $value = new \stdClass();

        self::assertSame($value, $kernel->handle('op', static fn(): \stdClass => $value));
    }

    #[Test]
    #[DataProvider('falsyReturnValueProvider')]
    public function handlePreservesFalsyReturnValuesWithoutCoercion(mixed $value): void
    {
        $kernel = new TraceKernel($this->scope(), $this->writer());

        self::assertSame($value, $kernel->handle('op', static fn(): mixed => $value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function falsyReturnValueProvider(): array
    {
        return [
            'null'         => [null],
            'integer zero' => [0],
            'false'        => [false],
            'empty string' => [''],
            'empty array'  => [[]],
        ];
    }

    #[Test]
    public function handleFlushesExactlyOneRootSpanRecord(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok');

        self::assertCount(1, $writer->records);
        self::assertSame('span', $writer->records[0]['type']);
    }

    #[Test]
    public function handleOpensTheRootSpanAsServerKind(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok');

        self::assertCount(1, $writer->records);
        self::assertSame(SpanKind::Server->value, $writer->records[0]['kind']);
        self::assertSame('server', $writer->records[0]['kind']);
    }

    #[Test]
    public function handleNamesTheRootSpanWithTheGivenName(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('GET /users/42', static fn(): string => 'ok');

        self::assertCount(1, $writer->records);
        self::assertSame('GET /users/42', $writer->records[0]['name']);
    }

    #[Test]
    public function handleLeavesTheStatusUnsetOnSuccess(): void
    {
        // The kernel never marks a successful root 'ok' — it stays at the OTel
        // default 'unset'. This documents that contract.
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok');

        self::assertCount(1, $writer->records);
        self::assertSame('unset', $writer->records[0]['status']);
        self::assertSame('', $writer->records[0]['status_message']);
    }

    #[Test]
    public function handleRootSpanHasNoParentForAFreshTrace(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok');

        self::assertCount(1, $writer->records);
        self::assertNull($writer->records[0]['parent_span_id']);
    }

    #[Test]
    public function handleRootSpanGetsA32HexTraceIdAnd16HexSpanId(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok');

        self::assertCount(1, $writer->records);
        self::assertIsString($writer->records[0]['trace_id']);
        self::assertIsString($writer->records[0]['span_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $writer->records[0]['trace_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $writer->records[0]['span_id']);
    }

    #[Test]
    public function handleCarriesTheDefaultAppChannelWithEmptyAttributesAndEvents(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok');

        self::assertCount(1, $writer->records);
        self::assertSame('app', $writer->records[0]['channel']);
        self::assertSame([], $writer->records[0]['attributes']);
        self::assertSame([], $writer->records[0]['events']);
    }

    #[Test]
    public function handleActivatesARootSpanForTheDurationOfWork(): void
    {
        $writer = $this->writer();
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $writer);

        $duringWork = null;
        $kernel->handle('op', function () use ($scope, &$duringWork): string {
            $duringWork = $scope->current();

            return 'ok';
        });

        self::assertInstanceOf(SpanInterface::class, $duringWork);
        // The active span during work is the very root the kernel later flushed.
        self::assertCount(1, $writer->records);
        self::assertSame($writer->records[0]['trace_id'], $duringWork->context()->traceId());
        self::assertSame($writer->records[0]['span_id'], $duringWork->context()->spanId());
    }

    #[Test]
    public function handleDrainsTheScopeAfterWorkCompletes(): void
    {
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $this->writer());

        $kernel->handle('op', static fn(): string => 'ok');

        self::assertNull($scope->current());
    }

    #[Test]
    public function eachFreshHandleMintsADistinctTraceId(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('first', static fn(): string => 'ok');
        $kernel->handle('second', static fn(): string => 'ok');

        self::assertCount(2, $writer->records);
        self::assertNotSame($writer->records[0]['trace_id'], $writer->records[1]['trace_id']);
    }

    // ----------------------------------------------------------------------
    // handle() — error path
    // ----------------------------------------------------------------------

    #[Test]
    public function handleReThrowsTheExceptionWorkRaises(): void
    {
        $kernel = new TraceKernel($this->scope(), $this->writer());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $kernel->handle('op', static fn(): mixed => throw new \RuntimeException('boom'));
    }

    #[Test]
    public function handleReThrowsTheVerySameExceptionInstance(): void
    {
        $kernel = new TraceKernel($this->scope(), $this->writer());

        $thrown = new \LogicException('original');

        try {
            $kernel->handle('op', static fn(): mixed => throw $thrown);
            self::fail('Expected the exception to propagate out of handle().');
        } catch (\LogicException $caught) {
            self::assertSame($thrown, $caught);
        }
    }

    #[Test]
    public function handleMarksTheRootSpanErrorWithTheExceptionMessageOnThrow(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        try {
            $kernel->handle('op', static fn(): mixed => throw new \RuntimeException('it failed'));
            self::fail('Expected the exception to propagate out of handle().');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertCount(1, $writer->records);
        self::assertSame('error', $writer->records[0]['status']);
        self::assertSame('it failed', $writer->records[0]['status_message']);
    }

    #[Test]
    public function handleStillFlushesTheRootSpanWhenWorkThrows(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        try {
            $kernel->handle('op', static fn(): mixed => throw new \RuntimeException('boom'));
            self::fail('Expected the exception to propagate out of handle().');
        } catch (\RuntimeException) {
            // expected
        }

        // The finally branch ended and exported the root span despite the throw.
        self::assertCount(1, $writer->records);
        self::assertSame('span', $writer->records[0]['type']);
    }

    #[Test]
    public function handleDrainsTheScopeEvenWhenWorkThrows(): void
    {
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $this->writer());

        try {
            $kernel->handle('op', static fn(): mixed => throw new \RuntimeException('boom'));
            self::fail('Expected the exception to propagate out of handle().');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertNull($scope->current());
    }

    // ----------------------------------------------------------------------
    // handle() — inbound W3C traceparent continuation
    // ----------------------------------------------------------------------

    #[Test]
    public function handleContinuesAValidInboundTraceKeepingItsTraceId(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle(
            'op',
            static fn(): string => 'ok',
            sprintf('00-%s-%s-01', self::TRACE, self::SPAN),
        );

        self::assertCount(1, $writer->records);
        self::assertSame(self::TRACE, $writer->records[0]['trace_id']);
    }

    #[Test]
    public function handleRecordsTheInboundSpanAsTheRootsParent(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle(
            'op',
            static fn(): string => 'ok',
            sprintf('00-%s-%s-01', self::TRACE, self::SPAN),
        );

        self::assertCount(1, $writer->records);
        self::assertSame(self::SPAN, $writer->records[0]['parent_span_id']);
    }

    #[Test]
    public function handleGivesTheContinuedRootAFreshSpanIdNotTheInboundSpanId(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle(
            'op',
            static fn(): string => 'ok',
            sprintf('00-%s-%s-01', self::TRACE, self::SPAN),
        );

        self::assertCount(1, $writer->records);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $writer->records[0]['span_id']);
        self::assertNotSame(self::SPAN, $writer->records[0]['span_id']);
    }

    #[Test]
    public function handleContinuesAValidInboundTraceEvenWithExtraFlagBits(): void
    {
        // Inbound 'ff' flags carry extra bits; continuation must still succeed and
        // keep the inbound trace id (not fall back to a fresh root).
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle(
            'op',
            static fn(): string => 'ok',
            sprintf('00-%s-%s-ff', self::TRACE, self::SPAN),
        );

        self::assertCount(1, $writer->records);
        self::assertSame(self::TRACE, $writer->records[0]['trace_id']);
        self::assertSame(self::SPAN, $writer->records[0]['parent_span_id']);
    }

    // ----------------------------------------------------------------------
    // handle() — malformed / absent inbound header falls back to a fresh root
    // ----------------------------------------------------------------------

    #[Test]
    #[DataProvider('malformedTraceparentProvider')]
    public function handleDoesNotCrashOnAMalformedTraceparentAndStartsAFreshRoot(string $traceparent): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        // A bad upstream header must never break the request: work still runs and
        // returns, and the root is a brand-new trace with no parent.
        $result = $kernel->handle('op', static fn(): string => 'still-ran', $traceparent);

        self::assertSame('still-ran', $result);
        self::assertCount(1, $writer->records);
        self::assertNull($writer->records[0]['parent_span_id']);
        self::assertIsString($writer->records[0]['trace_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $writer->records[0]['trace_id']);
        self::assertNotSame(self::TRACE, $writer->records[0]['trace_id']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedTraceparentProvider(): array
    {
        return [
            'plain garbage'        => ['not-a-traceparent'],
            'too few fields'       => ['00-' . self::TRACE],
            'too many fields'      => [sprintf('00-%s-%s-01-extra', self::TRACE, self::SPAN)],
            'forbidden ff version' => [sprintf('ff-%s-%s-01', self::TRACE, self::SPAN)],
            'all-zero trace id'    => [sprintf('00-%s-%s-01', str_repeat('0', 32), self::SPAN)],
            'all-zero span id'     => [sprintf('00-%s-%s-01', self::TRACE, str_repeat('0', 16))],
            'short trace id'       => [sprintf('00-abc-%s-01', self::SPAN)],
            'non-hex span id'      => [sprintf('00-%s-%s-01', self::TRACE, str_repeat('g', 16))],
            'single-digit flags'   => [sprintf('00-%s-%s-1', self::TRACE, self::SPAN)],
        ];
    }

    #[Test]
    public function handleTreatsAnEmptyTraceparentAsAFreshRoot(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok', '');

        self::assertCount(1, $writer->records);
        self::assertNull($writer->records[0]['parent_span_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $writer->records[0]['trace_id']);
    }

    #[Test]
    public function handleTreatsANullTraceparentAsAFreshRoot(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->handle('op', static fn(): string => 'ok', null);

        self::assertCount(1, $writer->records);
        self::assertNull($writer->records[0]['parent_span_id']);
    }

    // ----------------------------------------------------------------------
    // startRequest()
    // ----------------------------------------------------------------------

    #[Test]
    public function startRequestReturnsAndActivatesTheRootSpan(): void
    {
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $this->writer());

        $span = $kernel->startRequest('op');

        self::assertInstanceOf(SpanInterface::class, $span);
        self::assertSame($span, $scope->current());
    }

    #[Test]
    public function startRequestDoesNotFlushUntilTheRequestEnds(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->startRequest('op');

        // Nothing is exported while the root span is still open.
        self::assertCount(0, $writer->records);
    }

    #[Test]
    public function startRequestOpensAFreshRootWithNoParentByDefault(): void
    {
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $this->writer());

        $span = $kernel->startRequest('op');

        self::assertNull($span->context()->parentSpanId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->context()->traceId());
    }

    #[Test]
    public function startRequestContinuesAValidInboundTraceAndCarriesTraceState(): void
    {
        // tracestate is not part of the exported span record, so observe it on the
        // returned root span's context directly.
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $this->writer());

        $span = $kernel->startRequest(
            'op',
            sprintf('00-%s-%s-01', self::TRACE, self::SPAN),
            'rojo=00f067aa0ba902b7',
        );

        $context = $span->context();
        self::assertInstanceOf(SpanContext::class, $context);
        self::assertSame(self::TRACE, $context->traceId());
        self::assertSame(self::SPAN, $context->parentSpanId());
        self::assertSame('rojo=00f067aa0ba902b7', $context->traceState());
    }

    // ----------------------------------------------------------------------
    // endRequest()
    // ----------------------------------------------------------------------

    #[Test]
    public function endRequestFlushesTheOpenRootSpan(): void
    {
        $writer = $this->writer();
        $kernel = new TraceKernel($this->scope(), $writer);

        $kernel->startRequest('op');
        self::assertCount(0, $writer->records);

        $kernel->endRequest();

        self::assertCount(1, $writer->records);
        self::assertSame('span', $writer->records[0]['type']);
        self::assertSame('op', $writer->records[0]['name']);
    }

    #[Test]
    public function endRequestClearsTheActiveSpan(): void
    {
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $this->writer());

        $kernel->startRequest('op');
        $kernel->endRequest();

        self::assertNull($scope->current());
    }

    #[Test]
    public function endRequestIsSafeWhenNothingIsOpen(): void
    {
        $writer = $this->writer();
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $writer);

        $kernel->endRequest();

        self::assertCount(0, $writer->records);
        self::assertNull($scope->current());
    }

    #[Test]
    public function endRequestEndsEverySpanLeftOpenInnermostFirst(): void
    {
        // A child span left open at the request boundary must still be drained and
        // exported, innermost first, before the root.
        $writer = $this->writer();
        $scope  = $this->scope();
        $kernel = new TraceKernel($scope, $writer);

        $root  = $kernel->startRequest('root-op');
        $child = new CoreSpan(
            SpanContext::childOf($root->context()),
            'child-op',
            SpanKind::Internal,
            $writer,
            $scope,
        );
        $scope->activate($child);

        $kernel->endRequest();

        self::assertCount(2, $writer->records);
        self::assertSame('child-op', $writer->records[0]['name']);
        self::assertSame('root-op', $writer->records[1]['name']);
        // Both spans belong to the one trace.
        self::assertSame($writer->records[1]['trace_id'], $writer->records[0]['trace_id']);
        // The child's parent is the root span.
        self::assertSame($writer->records[1]['span_id'], $writer->records[0]['parent_span_id']);
        self::assertNull($scope->current());
    }
}
