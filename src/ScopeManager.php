<?php

declare(strict_types=1);

/**
 * Scope Manager
 *
 * Tracks the active-span stack per unit of execution. It is a `#[Singleton]` so
 * that singleton services (and the singleton {@see CoreTracer}) can inject it —
 * the container's scope rules forbid a singleton from depending on a scoped
 * service. Coroutine isolation therefore does NOT come from the instance: the
 * single ScopeManager keeps each coroutine's {@see SpanStack} inside that
 * coroutine's {@see \PHPdot\Contracts\Container\ContextInterface}, looked up via
 * the {@see ContextProviderInterface} on every call. Two coroutines get two
 * stacks; neither can see the other's current span.
 *
 * The Context is cleared when the coroutine ends, so the stack is freed
 * automatically; `close()` additionally ends any span left open at the request
 * boundary (LIFO) so a forgotten `end()` cannot leak a span.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Container\ContextDestroyInterface;
use PHPdot\Contracts\Container\ContextProviderInterface;
use PHPdot\Contracts\Logs\ScopeManagerInterface;
use PHPdot\Contracts\Logs\SpanInterface;

#[Singleton]
#[Binds(ScopeManagerInterface::class)]
final class ScopeManager implements ScopeManagerInterface
{
    /** Context key under which the per-coroutine SpanStack object is stored. */
    private const string STACK_KEY = 'phpdot.logs.span_stack';

    /**
     * @param ContextProviderInterface $contexts Resolves the active per-coroutine context.
     */
    public function __construct(
        private readonly ContextProviderInterface $contexts,
    ) {}

    public function current(): ?SpanInterface
    {
        return $this->stack()->current();
    }

    public function activate(SpanInterface $span): void
    {
        $this->stack()->push($span);
    }

    public function deactivate(SpanInterface $span): void
    {
        $this->stack()->remove($span);
    }

    public function close(): void
    {
        foreach ($this->stack()->drain() as $span) {
            $span->end();
        }
    }

    /**
     * The active-span stack for the current coroutine, created on first use and
     * stored in that coroutine's context so it is naturally isolated and freed.
     *
     * @return SpanStack The current coroutine's stack.
     */
    private function stack(): SpanStack
    {
        $context = $this->contexts->getContext();
        $stack   = $context->get(self::STACK_KEY);

        if (!$stack instanceof SpanStack) {
            $stack = new SpanStack();
            $context->set(self::STACK_KEY, $stack);

            // Drain automatically when the coroutine/context ends, so callers never
            // close() manually and any span left open at shutdown is still exported.
            if ($context instanceof ContextDestroyInterface) {
                $context->onDestroy(function (): void {
                    $this->close();
                });
            }
        }

        return $stack;
    }
}
