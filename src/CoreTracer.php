<?php

declare(strict_types=1);

/**
 * Core Tracer
 *
 * The engine's tracer and the default `TracerInterface` binding. It is a stateless
 * `#[Singleton]`: it holds no per-request state, reading the current span from the
 * `#[Scoped]` (per-coroutine) {@see ScopeManager} on every call. That split is what
 * makes a single process-wide tracer safe under Swoole — identity and the active
 * span stack are coroutine-local, the tracer is not.
 *
 * A span's trace id is derived from the current span's context (the kernel seeds a
 * root before any package runs), so the whole request shares one trace id and the
 * tracer never self-mints over a context that already exists.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Logs\PendingLogInterface;
use PHPdot\Contracts\Logs\ScopeManagerInterface;
use PHPdot\Contracts\Logs\SpanContextInterface;
use PHPdot\Contracts\Logs\SpanInterface;
use PHPdot\Contracts\Logs\TracerInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\Enum\SpanKind;
use PHPdot\Logs\Enum\SpanStatus;
use PHPdot\Logs\Trace\SpanContext;

#[Singleton]
#[Binds(TracerInterface::class)]
final class CoreTracer implements TracerInterface
{
    /**
     * @param ScopeManagerInterface $scope The per-coroutine active-span stack.
     * @param WriterInterface $writer The configured backend (NullWriter by default).
     * @param string $channel The channel this tracer's records are tagged with.
     */
    public function __construct(
        private readonly ScopeManagerInterface $scope,
        private readonly WriterInterface $writer,
        private readonly string $channel = 'app',
    ) {}

    /**
     * A tracer scoped to a named channel: its logs and spans carry the channel so a
     * backend routes them to their own stream (tracelog writes `{channel}.log`).
     * Trace identity is unchanged — channels share the request's trace_id.
     *
     * @param string $name The channel name (e.g. 'http', 'auth', 'db').
     */
    public function channel(string $name): self
    {
        return new self($this->scope, $this->writer, $name);
    }

    public function span(string $name, string $kind = 'internal'): SpanInterface
    {
        $parent = $this->scope->current();

        $context = $parent !== null
            ? SpanContext::childOf($parent->context())
            : SpanContext::root();

        $span = new CoreSpan($context, $name, SpanKind::fromString($kind), $this->writer, $this->scope, $this->channel);
        $this->scope->activate($span);

        return $span;
    }

    public function current(): SpanInterface
    {
        $current = $this->scope->current();

        if ($current !== null) {
            return $current;
        }

        // Logging before the kernel seeded a root: lazily start one so every log
        // line still carries a correlated trace/span id rather than dropping it.
        return $this->span('root', SpanKind::Internal->value);
    }

    public function context(): SpanContextInterface
    {
        return $this->current()->context();
    }

    /**
     * @template T
     *
     * @param callable(SpanInterface): T $callback
     *
     * @return T
     */
    public function trace(string $name, string $kind, callable $callback): mixed
    {
        $span = $this->span($name, $kind);

        try {
            return $callback($span);
        } catch (\Throwable $error) {
            $span->setStatus(SpanStatus::Error->value, $error->getMessage());

            throw $error;
        } finally {
            $span->end();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): PendingLogInterface
    {
        return $this->writeLog('debug', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): PendingLogInterface
    {
        return $this->writeLog('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): PendingLogInterface
    {
        return $this->writeLog('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): PendingLogInterface
    {
        return $this->writeLog('error', $message, $context);
    }

    /**
     * Build a log record on this tracer's channel, correlated to the current
     * span's identity, as a deferred handle: it is written when the handle is
     * released (end of statement) so the caller can secure() it first.
     *
     * @param array<string, mixed> $context
     */
    private function writeLog(string $level, string $message, array $context): PendingLog
    {
        $identity = $this->current()->context();

        return new PendingLog($this->writer, [
            'type'      => 'log',
            'level'     => $level,
            'message'   => $message,
            'channel'   => $this->channel,
            'trace_id'  => $identity->traceId(),
            'span_id'   => $identity->spanId(),
            'timestamp' => microtime(true),
            'context'   => $context,
        ]);
    }
}
