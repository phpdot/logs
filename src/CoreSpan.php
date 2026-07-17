<?php

declare(strict_types=1);

/**
 * Core Span
 *
 * The engine's span: a mutable, coroutine-bound handle that accumulates
 * attributes, events, and status, emits correlated log records on demand, and on
 * `end()` exports a single span record to the writer. It is created by
 * {@see CoreTracer} (child spans) or by the kernel (the request root span) and is
 * never resolved from the container — the container only knows the tracer and the
 * scope manager.
 *
 * Every record handed to the writer is an `array<string, mixed>` carrying the
 * trace/span identity, so a writer never depends on the engine's concrete types.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs;

use PHPdot\Contracts\Logs\PendingLogInterface;
use PHPdot\Contracts\Logs\ScopeManagerInterface;
use PHPdot\Contracts\Logs\SpanContextInterface;
use PHPdot\Contracts\Logs\SpanInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\Logs\Enum\SpanKind;
use PHPdot\Logs\Enum\SpanStatus;

final class CoreSpan implements SpanInterface
{
    /**
     * @var array<string, string|int|float|bool>
     */
    private array $attributes = [];

    /**
     * @var list<array{name: string, timestamp: float, attributes: array<string, string|int|float|bool>}>
     */
    private array $events = [];

    private SpanStatus $status = SpanStatus::Unset;
    private string $statusDescription = '';
    private readonly float $startedAt;
    private bool $ended = false;

    /**
     * Create a live span with its identity, timing, and export wiring.
     *
     * @param SpanContextInterface $context The W3C identity of this span.
     * @param string $name The span name.
     * @param SpanKind $kind The span kind.
     * @param WriterInterface $writer The configured backend records are written to.
     * @param ScopeManagerInterface $scope The per-coroutine scope this span deactivates from on end().
     * @param string $channel The channel this span's records are tagged with.
     */
    public function __construct(
        private readonly SpanContextInterface $context,
        private readonly string $name,
        private readonly SpanKind $kind,
        private readonly WriterInterface $writer,
        private readonly ScopeManagerInterface $scope,
        private readonly string $channel = 'app',
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * Set attribute.
     *
     * @param string $key
     * @param string|int|float|bool $value
     *
     * @return static
     */
    public function setAttribute(string $key, string|int|float|bool $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Record a timestamped named event on the span.
     *
     * @param array<string, string|int|float|bool> $attributes
     * @param string $name
     *
     * @return static
     */
    public function addEvent(string $name, array $attributes = []): static
    {
        $this->events[] = [
            'name'       => $name,
            'timestamp'  => microtime(true),
            'attributes' => $attributes,
        ];

        return $this;
    }

    /**
     * Set status.
     *
     * @param string $status
     * @param string $description
     *
     * @return static
     */
    public function setStatus(string $status, string $description = ''): static
    {
        $this->status            = SpanStatus::fromString($status);
        $this->statusDescription = $description;

        return $this;
    }

    /**
     * Context.
     *
     * @return SpanContextInterface
     */
    public function context(): SpanContextInterface
    {
        return $this->context;
    }

    /**
     * Write a debug-level line correlated to the current span.
     *
     * @param array<string, mixed> $context
     * @param string $message
     *
     * @return PendingLogInterface
     */
    public function debug(string $message, array $context = []): PendingLogInterface
    {
        return $this->log('debug', $message, $context);
    }

    /**
     * Write an info-level line correlated to the current span.
     *
     * @param array<string, mixed> $context
     * @param string $message
     *
     * @return PendingLogInterface
     */
    public function info(string $message, array $context = []): PendingLogInterface
    {
        return $this->log('info', $message, $context);
    }

    /**
     * Write a warning-level line correlated to the current span.
     *
     * @param array<string, mixed> $context
     * @param string $message
     *
     * @return PendingLogInterface
     */
    public function warning(string $message, array $context = []): PendingLogInterface
    {
        return $this->log('warning', $message, $context);
    }

    /**
     * Write an error-level line correlated to the current span.
     *
     * @param array<string, mixed> $context
     * @param string $message
     *
     * @return PendingLogInterface
     */
    public function error(string $message, array $context = []): PendingLogInterface
    {
        return $this->log('error', $message, $context);
    }

    /**
     * End.
     *
     * @return void
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $endedAt     = microtime(true);

        $this->writer->write([
            'type'           => 'span',
            'name'           => $this->name,
            'kind'           => $this->kind->value,
            'channel'        => $this->channel,
            'trace_id'       => $this->context->traceId(),
            'span_id'        => $this->context->spanId(),
            'parent_span_id' => $this->context->parentSpanId(),
            'started_at'     => $this->startedAt,
            'ended_at'       => $endedAt,
            'duration_ms'    => ($endedAt - $this->startedAt) * 1000.0,
            'status'         => $this->status->value,
            'status_message' => $this->statusDescription,
            'attributes'     => $this->attributes,
            'events'         => $this->events,
        ]);

        $this->scope->deactivate($this);
    }

    /**
     * Emit a log record correlated to this span's trace/span identity.
     *
     * @param array<string, mixed> $context
     * @param string $level
     * @param string $message
     *
     * @return PendingLog
     */
    private function log(string $level, string $message, array $context): PendingLog
    {
        return new PendingLog($this->writer, [
            'type'      => 'log',
            'level'     => $level,
            'message'   => $message,
            'channel'   => $this->channel,
            'trace_id'  => $this->context->traceId(),
            'span_id'   => $this->context->spanId(),
            'timestamp' => microtime(true),
            'context'   => $context,
        ]);
    }
}
