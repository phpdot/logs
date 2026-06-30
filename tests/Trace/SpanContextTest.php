<?php

declare(strict_types=1);

/**
 * Span Context Test
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Tests\Trace;

use PHPdot\Contracts\Logs\SpanContextInterface;
use PHPdot\Logs\Exception\InvalidIdentifierException;
use PHPdot\Logs\Trace\SpanContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpanContextTest extends TestCase
{
    /** Canonical W3C Trace Context example trace id (32 lowercase hex). */
    private const string TRACE = '0af7651916cd43dd8448eb211c80319c';

    /** Canonical W3C Trace Context example span id (16 lowercase hex). */
    private const string SPAN = 'b7ad6b7169203331';

    // ----------------------------------------------------------------------
    // root()
    // ----------------------------------------------------------------------

    #[Test]
    public function rootHasNoParent(): void
    {
        self::assertNull(SpanContext::root()->parentSpanId());
    }

    #[Test]
    public function rootIsSampledByDefault(): void
    {
        self::assertTrue(SpanContext::root()->sampled());
    }

    #[Test]
    public function rootCanBeMintedUnsampled(): void
    {
        self::assertFalse(SpanContext::root(false)->sampled());
    }

    #[Test]
    public function rootHasEmptyTraceState(): void
    {
        self::assertSame('', SpanContext::root()->traceState());
    }

    #[Test]
    public function rootGeneratesA32CharLowercaseHexTraceId(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', SpanContext::root()->traceId());
    }

    #[Test]
    public function rootGeneratesA16CharLowercaseHexSpanId(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', SpanContext::root()->spanId());
    }

    #[Test]
    public function rootTraceIdIsNotTheAllZeroSentinel(): void
    {
        self::assertNotSame(str_repeat('0', 32), SpanContext::root()->traceId());
    }

    #[Test]
    public function rootSpanIdIsNotTheAllZeroSentinel(): void
    {
        self::assertNotSame(str_repeat('0', 16), SpanContext::root()->spanId());
    }

    #[Test]
    public function eachRootGetsADistinctTraceId(): void
    {
        self::assertNotSame(SpanContext::root()->traceId(), SpanContext::root()->traceId());
    }

    #[Test]
    public function eachRootGetsADistinctSpanId(): void
    {
        self::assertNotSame(SpanContext::root()->spanId(), SpanContext::root()->spanId());
    }

    // ----------------------------------------------------------------------
    // toTraceparent()
    // ----------------------------------------------------------------------

    #[Test]
    public function toTraceparentMatchesTheCanonicalVersion00Grammar(): void
    {
        self::assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/',
            SpanContext::root()->toTraceparent(),
        );
    }

    #[Test]
    public function toTraceparentEmbedsThisContextsTraceAndSpanIds(): void
    {
        $context = SpanContext::root();

        self::assertSame(
            sprintf('00-%s-%s-01', $context->traceId(), $context->spanId()),
            $context->toTraceparent(),
        );
    }

    #[Test]
    public function toTraceparentEndsIn01WhenSampled(): void
    {
        self::assertStringEndsWith('-01', SpanContext::root(true)->toTraceparent());
    }

    #[Test]
    public function toTraceparentEndsIn00WhenNotSampled(): void
    {
        self::assertStringEndsWith('-00', SpanContext::root(false)->toTraceparent());
    }

    #[Test]
    public function toTraceparentUsesThisSpansOwnIdNotTheParentId(): void
    {
        $parent = SpanContext::root();
        $child = SpanContext::childOf($parent);

        self::assertStringContainsString($child->spanId(), $child->toTraceparent());
        self::assertStringNotContainsString($parent->spanId(), $child->toTraceparent());
    }

    // ----------------------------------------------------------------------
    // childOf()
    // ----------------------------------------------------------------------

    #[Test]
    public function childSharesTheParentsTraceId(): void
    {
        $parent = SpanContext::root();

        self::assertSame($parent->traceId(), SpanContext::childOf($parent)->traceId());
    }

    #[Test]
    public function childGetsAFreshSpanIdDistinctFromTheParent(): void
    {
        $parent = SpanContext::root();
        $child = SpanContext::childOf($parent);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $child->spanId());
        self::assertNotSame($parent->spanId(), $child->spanId());
    }

    #[Test]
    public function childRecordsTheParentsSpanIdAsItsParent(): void
    {
        $parent = SpanContext::root();

        self::assertSame($parent->spanId(), SpanContext::childOf($parent)->parentSpanId());
    }

    #[Test]
    public function childInheritsTheSampledTrueDecision(): void
    {
        self::assertTrue(SpanContext::childOf(SpanContext::root(true))->sampled());
    }

    #[Test]
    public function childInheritsTheSampledFalseDecision(): void
    {
        self::assertFalse(SpanContext::childOf(SpanContext::root(false))->sampled());
    }

    #[Test]
    public function childCarriesForwardTheParentsTraceState(): void
    {
        $parent = SpanContext::fromTraceparent(
            sprintf('00-%s-%s-01', self::TRACE, self::SPAN),
            'vendor=value,other=123',
        );

        self::assertSame('vendor=value,other=123', SpanContext::childOf($parent)->traceState());
    }

    #[Test]
    public function twoChildrenOfTheSameParentGetDistinctSpanIds(): void
    {
        $parent = SpanContext::root();

        self::assertNotSame(
            SpanContext::childOf($parent)->spanId(),
            SpanContext::childOf($parent)->spanId(),
        );
    }

    #[Test]
    public function aGrandchildKeepsTheTraceIdAndChainsTheSpanLineage(): void
    {
        $root = SpanContext::root();
        $child = SpanContext::childOf($root);
        $grandchild = SpanContext::childOf($child);

        self::assertSame($root->traceId(), $grandchild->traceId());
        self::assertSame($child->spanId(), $grandchild->parentSpanId());
        self::assertNotSame($child->spanId(), $grandchild->spanId());
    }

    #[Test]
    public function childOfAForeignContextDerivesIdentityButDropsTraceState(): void
    {
        // A non-SpanContext implementation of the contract: its tracestate is not
        // accessible through the interface, so childOf() cannot carry it forward.
        $foreign = new class implements SpanContextInterface {
            public function traceId(): string
            {
                return '0af7651916cd43dd8448eb211c80319c';
            }

            public function spanId(): string
            {
                return 'b7ad6b7169203331';
            }

            public function parentSpanId(): ?string
            {
                return null;
            }

            public function sampled(): bool
            {
                return true;
            }

            public function toTraceparent(): string
            {
                return '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
            }
        };

        $child = SpanContext::childOf($foreign);

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $child->traceId());
        self::assertSame('b7ad6b7169203331', $child->parentSpanId());
        self::assertTrue($child->sampled());
        self::assertSame('', $child->traceState());
    }

    #[Test]
    public function childOfNormalizesAForeignParentsUppercaseIdentifiersToLowercase(): void
    {
        $foreign = new class implements SpanContextInterface {
            public function traceId(): string
            {
                return '0AF7651916CD43DD8448EB211C80319C';
            }

            public function spanId(): string
            {
                return 'B7AD6B7169203331';
            }

            public function parentSpanId(): ?string
            {
                return null;
            }

            public function sampled(): bool
            {
                return true;
            }

            public function toTraceparent(): string
            {
                return '';
            }
        };

        $child = SpanContext::childOf($foreign);

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $child->traceId());
        self::assertSame('b7ad6b7169203331', $child->parentSpanId());
    }

    #[Test]
    public function childOfThrowsWhenTheParentExposesAnInvalidTraceId(): void
    {
        $foreign = new class implements SpanContextInterface {
            public function traceId(): string
            {
                return 'not-a-valid-trace-id';
            }

            public function spanId(): string
            {
                return 'b7ad6b7169203331';
            }

            public function parentSpanId(): ?string
            {
                return null;
            }

            public function sampled(): bool
            {
                return true;
            }

            public function toTraceparent(): string
            {
                return '';
            }
        };

        $this->expectException(InvalidIdentifierException::class);

        SpanContext::childOf($foreign);
    }

    #[Test]
    public function childOfThrowsWhenTheParentExposesAnInvalidSpanId(): void
    {
        $foreign = new class implements SpanContextInterface {
            public function traceId(): string
            {
                return '0af7651916cd43dd8448eb211c80319c';
            }

            public function spanId(): string
            {
                return 'zzzzzzzzzzzzzzzz';
            }

            public function parentSpanId(): ?string
            {
                return null;
            }

            public function sampled(): bool
            {
                return true;
            }

            public function toTraceparent(): string
            {
                return '';
            }
        };

        $this->expectException(InvalidIdentifierException::class);

        SpanContext::childOf($foreign);
    }

    #[Test]
    public function childOfThrowsWhenTheParentExposesTheAllZeroSpanId(): void
    {
        $foreign = new class implements SpanContextInterface {
            public function traceId(): string
            {
                return '0af7651916cd43dd8448eb211c80319c';
            }

            public function spanId(): string
            {
                return '0000000000000000';
            }

            public function parentSpanId(): ?string
            {
                return null;
            }

            public function sampled(): bool
            {
                return true;
            }

            public function toTraceparent(): string
            {
                return '';
            }
        };

        $this->expectException(InvalidIdentifierException::class);

        SpanContext::childOf($foreign);
    }

    // ----------------------------------------------------------------------
    // fromTraceparent()
    // ----------------------------------------------------------------------

    #[Test]
    public function fromTraceparentDecodesTheRemoteIdentity(): void
    {
        $context = SpanContext::fromTraceparent(sprintf('00-%s-%s-01', self::TRACE, self::SPAN));

        self::assertSame(self::TRACE, $context->traceId());
        self::assertSame(self::SPAN, $context->spanId());
        self::assertTrue($context->sampled());
    }

    #[Test]
    public function fromTraceparentHasANullParentBecauseTheRemoteParentIsNotConveyed(): void
    {
        $context = SpanContext::fromTraceparent(sprintf('00-%s-%s-01', self::TRACE, self::SPAN));

        self::assertNull($context->parentSpanId());
    }

    #[Test]
    public function fromTraceparentIsUnsampledWhenTheSampledBitIsClear(): void
    {
        $context = SpanContext::fromTraceparent(sprintf('00-%s-%s-00', self::TRACE, self::SPAN));

        self::assertFalse($context->sampled());
    }

    #[Test]
    public function fromTraceparentCapturesTheCompanionTraceState(): void
    {
        $context = SpanContext::fromTraceparent(
            sprintf('00-%s-%s-01', self::TRACE, self::SPAN),
            'rojo=00f067aa0ba902b7',
        );

        self::assertSame('rojo=00f067aa0ba902b7', $context->traceState());
    }

    #[Test]
    public function fromTraceparentTrimsSurroundingWhitespaceFromTheTraceState(): void
    {
        $context = SpanContext::fromTraceparent(
            sprintf('00-%s-%s-01', self::TRACE, self::SPAN),
            '  rojo=00f067aa0ba902b7  ',
        );

        self::assertSame('rojo=00f067aa0ba902b7', $context->traceState());
    }

    #[Test]
    public function fromTraceparentDefaultsTheTraceStateToEmpty(): void
    {
        $context = SpanContext::fromTraceparent(sprintf('00-%s-%s-01', self::TRACE, self::SPAN));

        self::assertSame('', $context->traceState());
    }

    #[Test]
    public function fromTraceparentLowercasesAnUppercaseHeader(): void
    {
        $context = SpanContext::fromTraceparent(
            sprintf('00-%s-%s-01', strtoupper(self::TRACE), strtoupper(self::SPAN)),
        );

        self::assertSame(self::TRACE, $context->traceId());
        self::assertSame(self::SPAN, $context->spanId());
    }

    #[Test]
    public function fromTraceparentTrimsSurroundingWhitespaceFromTheHeader(): void
    {
        $context = SpanContext::fromTraceparent(
            "  \t00-" . self::TRACE . '-' . self::SPAN . "-01\n  ",
        );

        self::assertSame(self::TRACE, $context->traceId());
        self::assertSame(self::SPAN, $context->spanId());
        self::assertTrue($context->sampled());
    }

    #[Test]
    #[DataProvider('flagByteSampledProvider')]
    public function fromTraceparentReadsOnlyBitZeroOfTheFlagsByte(string $flags, bool $expected): void
    {
        $context = SpanContext::fromTraceparent(sprintf('00-%s-%s-%s', self::TRACE, self::SPAN, $flags));

        self::assertSame($expected, $context->sampled());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function flagByteSampledProvider(): array
    {
        return [
            'sampled only (01)' => ['01', true],
            'unsampled (00)' => ['00', false],
            'sampled + random bit (03)' => ['03', true],
            'random bit only (02)' => ['02', false],
            'all bits but sampled (fe)' => ['fe', false],
            'all bits set (ff)' => ['ff', true],
        ];
    }

    #[Test]
    public function fromTraceparentCollapsesTheFlagsByteToJustTheSampledBitOnReEmission(): void
    {
        // Inbound flags carry extra bits; the outbound header keeps only "sampled".
        $context = SpanContext::fromTraceparent(sprintf('00-%s-%s-ff', self::TRACE, self::SPAN));

        self::assertSame(sprintf('00-%s-%s-01', self::TRACE, self::SPAN), $context->toTraceparent());
    }

    #[Test]
    public function fromTraceparentAcceptsANonFfFutureVersionAndReEmitsAsVersion00(): void
    {
        $context = SpanContext::fromTraceparent(sprintf('01-%s-%s-01', self::TRACE, self::SPAN));

        self::assertSame(self::TRACE, $context->traceId());
        self::assertSame(sprintf('00-%s-%s-01', self::TRACE, self::SPAN), $context->toTraceparent());
    }

    #[Test]
    public function aCanonicalHeaderSurvivesADecodeEncodeRoundTrip(): void
    {
        $header = sprintf('00-%s-%s-01', self::TRACE, self::SPAN);

        self::assertSame($header, SpanContext::fromTraceparent($header)->toTraceparent());
    }

    // ----------------------------------------------------------------------
    // fromTraceparent() — error cases
    // ----------------------------------------------------------------------

    #[Test]
    #[DataProvider('malformedHeaderProvider')]
    public function fromTraceparentRejectsMalformedOrForbiddenHeaders(string $header): void
    {
        $this->expectException(InvalidIdentifierException::class);

        SpanContext::fromTraceparent($header);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedHeaderProvider(): array
    {
        return [
            'empty string' => [''],
            'too few fields' => ['00-' . self::TRACE],
            'too many fields' => [sprintf('00-%s-%s-01-extra', self::TRACE, self::SPAN)],
            'forbidden ff version' => [sprintf('ff-%s-%s-01', self::TRACE, self::SPAN)],
            'non-hex version' => [sprintf('zz-%s-%s-01', self::TRACE, self::SPAN)],
            'all-zero trace id' => [sprintf('00-%s-%s-01', str_repeat('0', 32), self::SPAN)],
            'all-zero span id' => [sprintf('00-%s-%s-01', self::TRACE, str_repeat('0', 16))],
            'short trace id' => [sprintf('00-abc-%s-01', self::SPAN)],
            'short span id' => [sprintf('00-%s-abc-01', self::TRACE)],
            'non-hex trace id' => [sprintf('00-%s-%s-01', str_repeat('g', 32), self::SPAN)],
            'non-hex span id' => [sprintf('00-%s-%s-01', self::TRACE, str_repeat('g', 16))],
            'non-hex flags' => [sprintf('00-%s-%s-xy', self::TRACE, self::SPAN)],
            'single-digit flags' => [sprintf('00-%s-%s-1', self::TRACE, self::SPAN)],
        ];
    }
}
