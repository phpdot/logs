<?php

declare(strict_types=1);

/**
 * Scope Manager Test
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Tests;

use Closure;
use PHPdot\Contracts\Container\ContextDestroyInterface;
use PHPdot\Contracts\Container\ContextInterface;
use PHPdot\Contracts\Container\ContextProviderInterface;
use PHPdot\Contracts\Logs\PendingLogInterface;
use PHPdot\Contracts\Logs\SpanContextInterface;
use PHPdot\Contracts\Logs\SpanInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\CoreTracer;
use PHPdot\Logs\ScopeManager;
use PHPdot\Logs\SpanStack;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeManagerTest extends TestCase
{
    // ----------------------------------------------------------------------
    // current()
    // ----------------------------------------------------------------------

    #[Test]
    public function currentIsNullOnAFreshStack(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));

        self::assertNull($manager->current());
    }

    #[Test]
    public function activateMakesTheSpanCurrent(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $span = $this->fakeSpan();

        $manager->activate($span);

        self::assertSame($span, $manager->current());
    }

    #[Test]
    public function currentReturnsTheInnermostOfSeveralActiveSpans(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $outer = $this->fakeSpan();
        $middle = $this->fakeSpan();
        $inner = $this->fakeSpan();

        $manager->activate($outer);
        $manager->activate($middle);
        $manager->activate($inner);

        self::assertSame($inner, $manager->current());
    }

    // ----------------------------------------------------------------------
    // activate() / deactivate() — LIFO
    // ----------------------------------------------------------------------

    #[Test]
    public function deactivateTheCurrentSpanRevealsThePreviouslyActiveOne(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $root = $this->fakeSpan();
        $child = $this->fakeSpan();

        $manager->activate($root);
        $manager->activate($child);
        $manager->deactivate($child);

        self::assertSame($root, $manager->current());
    }

    #[Test]
    public function deactivateRemovesByIdentityToleratingOutOfOrderEnds(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $root = $this->fakeSpan();
        $middle = $this->fakeSpan();
        $inner = $this->fakeSpan();

        $manager->activate($root);
        $manager->activate($middle);
        $manager->activate($inner);

        // End the middle frame out of order: the inner frame is still current.
        $manager->deactivate($middle);
        self::assertSame($inner, $manager->current());

        // Ending the inner frame now reveals the root, skipping the gone middle.
        $manager->deactivate($inner);
        self::assertSame($root, $manager->current());
    }

    #[Test]
    public function deactivateAnAbsentSpanIsANoOp(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $active = $this->fakeSpan();
        $stranger = $this->fakeSpan();

        $manager->activate($active);
        $manager->deactivate($stranger);

        self::assertSame($active, $manager->current());
    }

    #[Test]
    public function deactivateIsIdempotentWhenCalledTwice(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $root = $this->fakeSpan();
        $child = $this->fakeSpan();

        $manager->activate($root);
        $manager->activate($child);

        $manager->deactivate($child);
        $manager->deactivate($child);

        self::assertSame($root, $manager->current());
    }

    #[Test]
    public function deactivateOnAnEmptyStackDoesNotError(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));

        $manager->deactivate($this->fakeSpan());

        self::assertNull($manager->current());
    }

    #[Test]
    public function neitherActivateNorDeactivateEverExports(): void
    {
        $writer = $this->capturingWriter();
        $manager = new ScopeManager($this->provider($this->context()));
        $tracer = new CoreTracer($manager, $writer);

        // span() activates on the scope; deactivate() must never write a record.
        $root = $tracer->span('root');
        $child = $tracer->span('child');
        $manager->deactivate($child);

        self::assertSame([], $writer->records);
        self::assertSame($root, $manager->current());
    }

    // ----------------------------------------------------------------------
    // deactivate() — root protection (contract conformance)
    // ----------------------------------------------------------------------

    #[Test]
    public function deactivateLeavesTheProtectedRootFrameInPlace(): void
    {
        // Contract: deactivate() must NOT remove the root frame (index 0); only
        // close()/drain() removes the root at the request boundary. Regression
        // guard for the SpanStack::remove() root-protection fix.
        $manager = new ScopeManager($this->provider($this->context()));
        $root = $this->fakeSpan();

        $manager->activate($root);
        $manager->deactivate($root);

        // Contract: the root frame is protected, so it stays current after deactivate().
        self::assertSame($root, $manager->current());
    }

    // ----------------------------------------------------------------------
    // close()
    // ----------------------------------------------------------------------

    #[Test]
    public function closeEndsEveryOpenSpan(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $root = $this->fakeSpan();
        $child = $this->fakeSpan();

        $manager->activate($root);
        $manager->activate($child);
        $manager->close();

        self::assertSame(1, $root->endCalls);
        self::assertSame(1, $child->endCalls);
    }

    #[Test]
    public function closeClearsTheStackSoCurrentIsNullAfterwards(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));

        $manager->activate($this->fakeSpan());
        $manager->activate($this->fakeSpan());
        $manager->close();

        self::assertNull($manager->current());
    }

    #[Test]
    public function closeOnAnEmptyStackIsANoOp(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));

        $manager->close();

        self::assertNull($manager->current());
    }

    #[Test]
    public function closeIsIdempotentAndDoesNotReEndAlreadyDrainedSpans(): void
    {
        $manager = new ScopeManager($this->provider($this->context()));
        $span = $this->fakeSpan();

        $manager->activate($span);
        $manager->close();
        $manager->close();

        self::assertSame(1, $span->endCalls);
    }

    #[Test]
    public function closeDrainsRealSpansInnermostFirstAndExportsOneRecordEach(): void
    {
        $writer = $this->capturingWriter();
        $manager = new ScopeManager($this->provider($this->context()));
        $tracer = new CoreTracer($manager, $writer);

        // The tracer creates and activates each span; child/grandchild nest the root.
        $tracer->span('root');
        $tracer->span('child');
        $tracer->span('grandchild');

        self::assertSame([], $writer->records, 'spans must not export before they end');

        $manager->close();

        $names = array_map(static fn(array $record): mixed => $record['name'], $writer->records);
        self::assertSame(['grandchild', 'child', 'root'], $names);
    }

    #[Test]
    public function closeExportsRecordsThatPreserveTheTraceLineage(): void
    {
        $writer = $this->capturingWriter();
        $manager = new ScopeManager($this->provider($this->context()));
        $tracer = new CoreTracer($manager, $writer);

        $tracer->span('root');
        $tracer->span('child');
        $tracer->span('grandchild');
        $manager->close();

        [$grandchild, $child, $root] = $writer->records;

        // One shared trace id across the whole drained stack.
        self::assertSame($root['trace_id'], $child['trace_id']);
        self::assertSame($root['trace_id'], $grandchild['trace_id']);

        // Parent linkage is innermost -> outermost; the root has no parent.
        self::assertNull($root['parent_span_id']);
        self::assertSame($root['span_id'], $child['parent_span_id']);
        self::assertSame($child['span_id'], $grandchild['parent_span_id']);
    }

    #[Test]
    public function closeExportsAFinishedSpanRecordWithTheFullShape(): void
    {
        $writer = $this->capturingWriter();
        $manager = new ScopeManager($this->provider($this->context()));
        $tracer = new CoreTracer($manager, $writer);

        $tracer->span('checkout', 'server')
            ->setAttribute('order.id', 42)
            ->setStatus('error', 'card declined');

        $manager->close();

        self::assertCount(1, $writer->records);
        $record = $writer->records[0];

        self::assertSame([
            'type',
            'name',
            'kind',
            'channel',
            'trace_id',
            'span_id',
            'parent_span_id',
            'started_at',
            'ended_at',
            'duration_ms',
            'status',
            'status_message',
            'attributes',
            'events',
        ], array_keys($record));

        self::assertSame('span', $record['type']);
        self::assertSame('checkout', $record['name']);
        self::assertSame('server', $record['kind']);
        self::assertSame('app', $record['channel']);
        self::assertSame('error', $record['status']);
        self::assertSame('card declined', $record['status_message']);
        self::assertSame(['order.id' => 42], $record['attributes']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $record['trace_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $record['span_id']);
    }

    // ----------------------------------------------------------------------
    // Per-coroutine storage via the context provider
    // ----------------------------------------------------------------------

    #[Test]
    public function theStackIsStoredInTheContextReturnedByTheProvider(): void
    {
        $context = $this->context();
        $manager = new ScopeManager($this->provider($context));

        $manager->activate($this->fakeSpan());

        self::assertCount(1, $context->store);
        self::assertInstanceOf(SpanStack::class, array_values($context->store)[0]);
    }

    #[Test]
    public function theSameStackInstanceIsReusedAcrossCalls(): void
    {
        $context = $this->context();
        $manager = new ScopeManager($this->provider($context));
        $span = $this->fakeSpan();

        $manager->activate($span);
        $manager->current();
        $manager->deactivate($span);
        $manager->activate($this->fakeSpan());

        // Repeated operations never create a second stack object in the context.
        self::assertCount(1, $context->store);
    }

    #[Test]
    public function eachCoroutineContextGetsItsOwnIsolatedStack(): void
    {
        $first = $this->context();
        $second = $this->context();

        // A single ScopeManager serving two coroutines via a switchable provider.
        $provider = new class implements ContextProviderInterface {
            public ContextInterface $active;

            public function getContext(): ContextInterface
            {
                return $this->active;
            }
        };
        $manager = new ScopeManager($provider);

        $spanA = $this->fakeSpan();
        $spanB = $this->fakeSpan();

        $provider->active = $first;
        $manager->activate($spanA);

        // Switching coroutine: the second context starts empty and cannot see span A.
        $provider->active = $second;
        self::assertNull($manager->current());

        $manager->activate($spanB);
        self::assertSame($spanB, $manager->current());

        // Switching back: the first coroutine's stack is intact and unaffected.
        $provider->active = $first;
        self::assertSame($spanA, $manager->current());
    }

    #[Test]
    public function closeOnlyDrainsTheActiveContextsStack(): void
    {
        $first = $this->context();
        $second = $this->context();

        $provider = new class implements ContextProviderInterface {
            public ContextInterface $active;

            public function getContext(): ContextInterface
            {
                return $this->active;
            }
        };
        $manager = new ScopeManager($provider);

        $spanA = $this->fakeSpan();
        $spanB = $this->fakeSpan();

        $provider->active = $first;
        $manager->activate($spanA);

        $provider->active = $second;
        $manager->activate($spanB);
        $manager->close();

        // Only the second coroutine's span was drained.
        self::assertSame(1, $spanB->endCalls);

        $provider->active = $first;
        self::assertSame(0, $spanA->endCalls);
        self::assertSame($spanA, $manager->current());
    }

    // ----------------------------------------------------------------------
    // Context destroy hook (automatic drain at coroutine end)
    // ----------------------------------------------------------------------

    #[Test]
    public function aDestroyCallbackIsRegisteredOnFirstStackUse(): void
    {
        $context = $this->destroyableContext();
        $manager = new ScopeManager($this->provider($context));

        $manager->current();

        self::assertCount(1, $context->callbacks);
    }

    #[Test]
    public function onlyOneDestroyCallbackIsRegisteredAcrossManyOperations(): void
    {
        $context = $this->destroyableContext();
        $manager = new ScopeManager($this->provider($context));

        $manager->activate($this->fakeSpan());
        $manager->activate($this->fakeSpan());
        $manager->current();
        $manager->deactivate($this->fakeSpan());

        self::assertCount(1, $context->callbacks);
    }

    #[Test]
    public function theDestroyCallbackDrainsAnyStillOpenSpans(): void
    {
        $context = $this->destroyableContext();
        $manager = new ScopeManager($this->provider($context));
        $root = $this->fakeSpan();
        $child = $this->fakeSpan();

        $manager->activate($root);
        $manager->activate($child);

        // Simulate the coroutine/context ending: fire the registered destroy hook.
        ($context->callbacks[0])();

        self::assertSame(1, $root->endCalls);
        self::assertSame(1, $child->endCalls);
        self::assertNull($manager->current());
    }

    #[Test]
    public function worksWithoutContextDestroySupport(): void
    {
        // A plain ContextInterface with no destroy capability must not break activation.
        $context = $this->context();
        $manager = new ScopeManager($this->provider($context));
        $span = $this->fakeSpan();

        $manager->activate($span);

        self::assertSame($span, $manager->current());
        self::assertInstanceOf(ContextInterface::class, $context);
        self::assertNotInstanceOf(ContextDestroyInterface::class, $context);
    }

    // ----------------------------------------------------------------------
    // Inline fakes
    // ----------------------------------------------------------------------

    /**
     * A bare in-memory context: a typed key/value store of object instances with
     * no destroy capability. `store` is exposed so tests can inspect what the
     * ScopeManager kept in the per-coroutine slot.
     */
    private function context(): ContextInterface
    {
        return new class implements ContextInterface {
            /** @var array<string, object> */
            public array $store = [];

            public function has(string $id): bool
            {
                return isset($this->store[$id]);
            }

            public function get(string $id): ?object
            {
                return $this->store[$id] ?? null;
            }

            public function set(string $id, object $instance): void
            {
                $this->store[$id] = $instance;
            }

            public function unset(string $id): void
            {
                unset($this->store[$id]);
            }

            public function reset(): void
            {
                $this->store = [];
            }
        };
    }

    /**
     * A context that also implements the optional destroy capability, capturing
     * every registered callback in `callbacks` so a test can fire them by hand.
     */
    private function destroyableContext(): ContextInterface
    {
        return new class implements ContextInterface, ContextDestroyInterface {
            /** @var array<string, object> */
            public array $store = [];

            /** @var list<Closure(): void> */
            public array $callbacks = [];

            public function has(string $id): bool
            {
                return isset($this->store[$id]);
            }

            public function get(string $id): ?object
            {
                return $this->store[$id] ?? null;
            }

            public function set(string $id, object $instance): void
            {
                $this->store[$id] = $instance;
            }

            public function unset(string $id): void
            {
                unset($this->store[$id]);
            }

            public function reset(): void
            {
                $this->store = [];
            }

            public function onDestroy(Closure $callback): void
            {
                $this->callbacks[] = $callback;
            }
        };
    }

    /**
     * A provider that always hands back the one context it was built with.
     */
    private function provider(ContextInterface $context): ContextProviderInterface
    {
        return new class ($context) implements ContextProviderInterface {
            public function __construct(private readonly ContextInterface $context) {}

            public function getContext(): ContextInterface
            {
                return $this->context;
            }
        };
    }

    /**
     * A minimal span that only records how many times it was ended — enough to
     * assert push/pop/drain semantics without pulling in the real CoreSpan.
     */
    private function fakeSpan(): SpanInterface
    {
        return new class implements SpanInterface {
            public int $endCalls = 0;

            public function setAttribute(string $key, string|int|float|bool $value): static
            {
                return $this;
            }

            public function addEvent(string $name, array $attributes = []): static
            {
                return $this;
            }

            public function setStatus(string $status, string $description = ''): static
            {
                return $this;
            }

            public function context(): SpanContextInterface
            {
                throw new \LogicException('fake span has no trace identity');
            }

            public function debug(string $message, array $context = []): PendingLogInterface
            {
                return $this->pendingNoop();
            }

            public function info(string $message, array $context = []): PendingLogInterface
            {
                return $this->pendingNoop();
            }

            public function warning(string $message, array $context = []): PendingLogInterface
            {
                return $this->pendingNoop();
            }

            public function error(string $message, array $context = []): PendingLogInterface
            {
                return $this->pendingNoop();
            }

            private function pendingNoop(): PendingLogInterface
            {
                return new class implements PendingLogInterface {
                    public function secure(): static
                    {
                        return $this;
                    }
                };
            }

            public function end(): void
            {
                ++$this->endCalls;
            }
        };
    }

    /**
     * A writer that captures every exported record so tests can assert on shape.
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
}
