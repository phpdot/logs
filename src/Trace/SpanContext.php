<?php

declare(strict_types=1);

/**
 * Span Context
 *
 * Immutable W3C trace identity carried by a span: trace id, span id, parent span
 * id, the sampled flag, and the propagated `tracestate`. The identity is decided
 * once at construction via one of the named factories and never re-derived.
 *
 * This is the contract-facing wrapper over the {@see Traceparent} codec and the
 * single canonical place to encode/decode the `traceparent` header.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Trace;

use PHPdot\Contracts\Logs\SpanContextInterface;

final class SpanContext implements SpanContextInterface
{
    /**
     * Create the immutable identity of one span within its trace.
     *
     * @param string $traceId 32 lowercase hex characters.
     * @param string $spanId 16 lowercase hex characters.
     * @param string|null $parentSpanId 16 lowercase hex characters, or null at the trace root.
     * @param bool $sampled The W3C sampled flag.
     * @param string $traceState The propagated `tracestate` value (may be empty).
     */
    private function __construct(
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly ?string $parentSpanId,
        private readonly bool $sampled,
        private readonly string $traceState,
    ) {}

    /**
     * Mint a fresh root context: a new trace id and span id, no parent.
     *
     * @param bool $sampled Whether the new trace is recorded.
     *
     * @return self The root span context.
     */
    public static function root(bool $sampled = true): self
    {
        return new self(
            TraceId::generate()->id(),
            SpanId::generate()->id(),
            null,
            $sampled,
            '',
        );
    }

    /**
     * Derive a child context: same trace and sampling as the parent, a fresh span
     * id, and the parent's span id recorded as the parent. The parent's
     * `tracestate` is carried forward when available.
     *
     * @param SpanContextInterface $parent The parent context.
     *
     * @return self The child span context.
     */
    public static function childOf(SpanContextInterface $parent): self
    {
        return new self(
            TraceId::fromString($parent->traceId())->id(),
            SpanId::generate()->id(),
            SpanId::fromString($parent->spanId())->id(),
            $parent->sampled(),
            $parent instanceof self ? $parent->traceState : '',
        );
    }

    /**
     * Decode an inbound W3C `traceparent` (and optional `tracestate`) into the
     * remote span's context. Its parent is null because the remote parent is not
     * conveyed by the header; pass the result to {@see self::childOf()} to start a
     * local span beneath it.
     *
     * @param string $header The inbound `traceparent` header value.
     * @param string|null $traceState The inbound `tracestate` header value, if present.
     *
     * @throws \PHPdot\Logs\Exception\InvalidIdentifierException When the header is invalid.
     *
     * @return self The decoded remote span context.
     */
    public static function fromTraceparent(string $header, ?string $traceState = null): self
    {
        $parent = Traceparent::parse($header, $traceState ?? '');

        return new self(
            $parent->traceId(),
            $parent->spanId(),
            null,
            $parent->sampled(),
            $parent->traceState(),
        );
    }

    /**
     * The W3C trace id (32 lowercase hex characters).
     *
     * @return string 32 hex characters.
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * The W3C span id (16 lowercase hex characters).
     *
     * @return string 16 hex characters.
     */
    public function spanId(): string
    {
        return $this->spanId;
    }

    /**
     * The parent span id, or null at the root of the trace.
     *
     * @return string|null 16 hex characters, or null.
     */
    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    /**
     * The W3C sampled flag.
     *
     * @return bool True when this trace is recorded.
     */
    public function sampled(): bool
    {
        return $this->sampled;
    }

    /**
     * The propagated `tracestate` value (empty when absent).
     *
     * @return string The vendor `tracestate` list.
     */
    public function traceState(): string
    {
        return $this->traceState;
    }

    /**
     * Emit the canonical version-`00` `traceparent` header for outbound propagation.
     *
     * @return string The `traceparent` header.
     */
    public function toTraceparent(): string
    {
        return sprintf(
            '00-%s-%s-%02x',
            $this->traceId,
            $this->spanId,
            $this->sampled ? 0x01 : 0x00,
        );
    }
}
