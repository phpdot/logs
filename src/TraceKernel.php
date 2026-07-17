<?php

declare(strict_types=1);

/**
 * Trace Kernel
 *
 * The request-boundary hook the server/composition root calls — once, before any
 * package runs — to give the whole request a single trace identity. It mints (or
 * continues, from an inbound W3C `traceparent`) the root span and activates it in
 * the {@see ScopeManager}, so every later `tracer->span()`/log inherits the same
 * trace id. At the end of the request it drains the scope, ending any span left
 * open. This is bootstrap-facing — packages use {@see \PHPdot\Contracts\Logs\TracerInterface},
 * not this.
 *
 * A `#[Singleton]`: it depends only on the singleton {@see ScopeManagerInterface}
 * + {@see WriterInterface}, and the per-coroutine root it activates lives in the
 * scope's per-coroutine storage — so concurrent requests never share a root.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Logs\ScopeManagerInterface;
use PHPdot\Contracts\Logs\SpanInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\Enum\SpanKind;
use PHPdot\Logs\Exception\InvalidIdentifierException;
use PHPdot\Logs\Trace\SpanContext;

#[Singleton]
final class TraceKernel
{
    /**
     * Create the kernel owning the trace lifecycle for one execution.
     *
     * @param ScopeManagerInterface $scope The per-coroutine active-span stack.
     * @param WriterInterface $writer The configured backend the root span exports to.
     */
    public function __construct(
        private readonly ScopeManagerInterface $scope,
        private readonly WriterInterface $writer,
    ) {}

    /**
     * Open the request's root span and make it the active span.
     *
     * When an inbound W3C `traceparent` is supplied and valid, the root continues
     * that distributed trace (same trace id, the remote span as its parent);
     * otherwise a fresh trace is minted. A malformed inbound header never throws —
     * it falls back to a fresh root so a bad upstream cannot break the request.
     *
     * @param string $name The root span name — the unit of work (e.g. 'GET /users',
     *                     a CLI command, a queue job). Required: the kernel is
     *                     transport-agnostic and never guesses it.
     * @param string|null $traceparent The inbound `traceparent` header, if any.
     * @param string|null $tracestate The inbound `tracestate` header, if any.
     *
     * @return SpanInterface The active root span.
     */
    public function startRequest(
        string $name,
        ?string $traceparent = null,
        ?string $tracestate = null,
    ): SpanInterface {
        $span = new CoreSpan(
            $this->rootContext($traceparent, $tracestate),
            $name,
            SpanKind::Server,
            $this->writer,
            $this->scope,
        );

        $this->scope->activate($span);

        return $span;
    }

    /**
     * Close the request: end every span still open (innermost first), freeing the
     * scope. Safe to call once per request in a `finally`.
     *
     * @return void
     */
    public function endRequest(): void
    {
        $this->scope->close();
    }

    /**
     * Run an entire request inside the root span — the one call a server makes.
     *
     * Seeds the trace (continuing an inbound `traceparent`), runs $work, marks the
     * active span 'error' if it throws (then re-throws), and ALWAYS ends the request
     * on the way out. Callers never close() manually.
     *
     * @template T
     *
     * @param string $name The root span name — the unit of work (route, command, job). Required.
     * @param callable(): T $work The request body; its return value is returned.
     * @param string|null $traceparent The inbound `traceparent` header, if any.
     * @param string|null $tracestate The inbound `tracestate` header, if any.
     *
     * @return T
     */
    public function handle(
        string $name,
        callable $work,
        ?string $traceparent = null,
        ?string $tracestate = null,
    ): mixed {
        $this->startRequest($name, $traceparent, $tracestate);

        try {
            return $work();
        } catch (\Throwable $error) {
            $this->scope->current()?->setStatus('error', $error->getMessage());

            throw $error;
        } finally {
            $this->endRequest();
        }
    }

    /**
     * Decide the root context: continue a valid inbound trace, else mint a
     * fresh one. A malformed upstream header never breaks the request — it
     * falls back to a fresh root.
     *
     * @param string|null $traceparent The inbound `traceparent` header.
     * @param string|null $tracestate The inbound `tracestate` header.
     *
     * @return SpanContext The root span context.
     */
    private function rootContext(?string $traceparent, ?string $tracestate): SpanContext
    {
        if ($traceparent !== null && $traceparent !== '') {
            try {
                return SpanContext::childOf(SpanContext::fromTraceparent($traceparent, $tracestate));
            } catch (InvalidIdentifierException) {
            }
        }

        return SpanContext::root();
    }
}
