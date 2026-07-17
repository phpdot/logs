<?php

declare(strict_types=1);

/**
 * Span Stack
 *
 * A mutable LIFO stack of the active spans for ONE unit of execution. It is the
 * object that {@see ScopeManager} stores in the per-coroutine
 * {@see \PHPdot\Contracts\Container\ContextInterface} (which holds objects, not
 * arrays) — so each coroutine owns its own stack and there is no cross-coroutine
 * bleed, while the ScopeManager itself stays a single shared instance.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs;

use PHPdot\Contracts\Logs\SpanInterface;

final class SpanStack
{
    /**
     * @var list<SpanInterface> The active spans, root at index 0.
     */
    private array $spans = [];

    /**
     * The innermost active span, or null when the stack is empty.
     *
     * @return SpanInterface|null The current span.
     */
    public function current(): ?SpanInterface
    {
        return $this->spans === [] ? null : $this->spans[array_key_last($this->spans)];
    }

    /**
     * Push a span as the new innermost span.
     *
     * @param SpanInterface $span The span to push.
     *
     * @return void
     */
    public function push(SpanInterface $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * Remove a span by identity, tolerating out-of-order ends.
     *
     * The root frame (index 0) is protected — only {@see drain()} removes it at
     * the request/coroutine boundary — so a stray `deactivate()` of the root is a
     * no-op and `current()` keeps reporting the request root.
     *
     * @param SpanInterface $span The span to remove.
     *
     * @return void
     */
    public function remove(SpanInterface $span): void
    {
        foreach ($this->spans as $index => $active) {
            if ($active === $span) {
                if ($index === 0) {
                    return;
                }

                array_splice($this->spans, $index, 1);

                return;
            }
        }
    }

    /**
     * Empty the stack and return the spans innermost-first, for draining at the
     * request/coroutine boundary.
     *
     * @return list<SpanInterface> The spans that were open, innermost first.
     */
    public function drain(): array
    {
        $remaining = array_reverse($this->spans);
        $this->spans = [];

        return $remaining;
    }
}
