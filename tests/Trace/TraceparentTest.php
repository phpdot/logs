<?php

declare(strict_types=1);

/**
 * Traceparent Test
 *
 * Exhaustive coverage of the W3C `traceparent` codec: decoding/validation,
 * flags + sampled-bit handling, canonical re-emission, round-tripping, and the
 * full matrix of malformed-input rejections.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Logs\Tests\Trace;

use InvalidArgumentException;
use PHPdot\Logs\Exception\InvalidIdentifierException;
use PHPdot\Logs\Trace\Traceparent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TraceparentTest extends TestCase
{
    /** Canonical W3C example trace id (32 hex). */
    private const string TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

    /** Canonical W3C example span id (16 hex). */
    private const string SPAN_ID = 'b7ad6b7169203331';

    // ---------------------------------------------------------------------
    // Happy path: decoding a valid header
    // ---------------------------------------------------------------------

    #[Test]
    public function parseDecodesCanonicalW3CHeader(): void
    {
        $tp = Traceparent::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01');

        self::assertInstanceOf(Traceparent::class, $tp);
        self::assertSame(self::TRACE_ID, $tp->traceId());
        self::assertSame(self::SPAN_ID, $tp->spanId());
        self::assertSame(1, $tp->flags());
        self::assertTrue($tp->sampled());
    }

    #[Test]
    public function parseDefaultsTraceStateToEmpty(): void
    {
        $tp = Traceparent::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01');

        self::assertSame('', $tp->traceState());
    }

    #[Test]
    public function parseStoresProvidedTraceState(): void
    {
        $tp = Traceparent::parse(
            '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01',
            'vendor1=value1,vendor2=value2',
        );

        self::assertSame('vendor1=value1,vendor2=value2', $tp->traceState());
    }

    #[Test]
    public function parseTrimsSurroundingWhitespaceFromTraceState(): void
    {
        $tp = Traceparent::parse(
            '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01',
            "  vendor=value  \n",
        );

        self::assertSame('vendor=value', $tp->traceState());
    }

    #[Test]
    public function parseNormalizesUppercaseHexToLowercase(): void
    {
        $tp = Traceparent::parse('00-0AF7651916CD43DD8448EB211C80319C-B7AD6B7169203331-01');

        self::assertSame(self::TRACE_ID, $tp->traceId());
        self::assertSame(self::SPAN_ID, $tp->spanId());
    }

    #[Test]
    public function parseTrimsSurroundingWhitespaceFromHeader(): void
    {
        $tp = Traceparent::parse("  00-" . self::TRACE_ID . '-' . self::SPAN_ID . "-01  \n");

        self::assertSame(self::TRACE_ID, $tp->traceId());
        self::assertSame(self::SPAN_ID, $tp->spanId());
        self::assertSame(1, $tp->flags());
    }

    // ---------------------------------------------------------------------
    // Flags + sampled bit
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function flagsProvider(): iterable
    {
        // hex flags byte, decoded int value, expected sampled bit
        yield 'unset 00'            => ['00', 0, false];
        yield 'sampled 01'          => ['01', 1, true];
        yield 'high-bits only a0'   => ['a0', 160, false];
        yield 'sampled + bit 1 = 03' => ['03', 3, true];
        yield 'even byte 02'        => ['02', 2, false];
        yield 'all bits ff'         => ['ff', 255, true];
        yield 'leading zero 0a'     => ['0a', 10, false];
    }

    #[Test]
    #[DataProvider('flagsProvider')]
    public function flagsReturnsRawDecodedByte(string $hexFlags, int $expectedInt, bool $expectedSampled): void
    {
        $tp = Traceparent::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-' . $hexFlags);

        self::assertSame($expectedInt, $tp->flags());
        // silence unused-arg lint while keeping the row shape uniform
        self::assertSame($expectedSampled, $tp->sampled());
    }

    #[Test]
    #[DataProvider('flagsProvider')]
    public function sampledReflectsBitZeroOfFlags(string $hexFlags, int $expectedInt, bool $expectedSampled): void
    {
        $tp = Traceparent::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-' . $hexFlags);

        self::assertSame($expectedSampled, $tp->sampled());
        self::assertSame($expectedInt, $tp->flags());
    }

    // ---------------------------------------------------------------------
    // toHeader / __toString / round trip
    // ---------------------------------------------------------------------

    #[Test]
    public function toHeaderEmitsCanonicalVersionZeroForm(): void
    {
        $header = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';

        self::assertSame($header, Traceparent::parse($header)->toHeader());
    }

    #[Test]
    public function toHeaderNormalizesUnknownVersionToZeroZero(): void
    {
        // A version other than 00 (but valid 2-hex, not ff) is accepted and
        // re-emitted in the canonical 00 form.
        $tp = Traceparent::parse('01-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01');

        self::assertSame('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01', $tp->toHeader());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function flagsReEmitProvider(): iterable
    {
        yield '00' => ['00'];
        yield '01' => ['01'];
        yield '0a' => ['0a'];
        yield 'a0' => ['a0'];
        yield 'ff' => ['ff'];
    }

    #[Test]
    #[DataProvider('flagsReEmitProvider')]
    public function toHeaderReEmitsFlagsByteAsTwoLowercaseHex(string $hexFlags): void
    {
        $tp = Traceparent::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-' . $hexFlags);

        self::assertSame(
            '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-' . $hexFlags,
            $tp->toHeader(),
        );
    }

    #[Test]
    public function toStringIsIdenticalToToHeader(): void
    {
        $tp = Traceparent::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-ff');

        self::assertSame($tp->toHeader(), (string) $tp);
        self::assertSame('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-ff', (string) $tp);
    }

    #[Test]
    public function roundTripPreservesIdentityAndFlags(): void
    {
        $original = Traceparent::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-a0');
        $reparsed = Traceparent::parse($original->toHeader());

        self::assertSame($original->traceId(), $reparsed->traceId());
        self::assertSame($original->spanId(), $reparsed->spanId());
        self::assertSame($original->flags(), $reparsed->flags());
        self::assertSame($original->sampled(), $reparsed->sampled());
        self::assertSame($original->toHeader(), $reparsed->toHeader());
    }

    // ---------------------------------------------------------------------
    // Malformed structure / version / flags -> "traceparent" rejection
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaderProvider(): iterable
    {
        $trace = self::TRACE_ID;
        $span  = self::SPAN_ID;

        yield 'empty string'        => [''];
        yield 'whitespace only'     => ['   '];
        yield 'no dashes'           => ['00' . $trace . $span . '01'];
        yield 'three parts'         => ["00-{$trace}-{$span}"];
        yield 'two parts'           => ["00-{$trace}"];
        yield 'five parts'          => ["00-{$trace}-{$span}-01-extra"];
        yield 'trailing dash'       => ["00-{$trace}-{$span}-01-"];
        yield 'non-hex version'     => ["gg-{$trace}-{$span}-01"];
        yield 'single-char version' => ["0-{$trace}-{$span}-01"];
        yield 'three-char version'  => ["000-{$trace}-{$span}-01"];
        yield 'forbidden ff version' => ["ff-{$trace}-{$span}-01"];
        yield 'non-hex flags'       => ["00-{$trace}-{$span}-zz"];
        yield 'single-char flags'   => ["00-{$trace}-{$span}-1"];
        yield 'three-char flags'    => ["00-{$trace}-{$span}-011"];
    }

    #[Test]
    #[DataProvider('malformedHeaderProvider')]
    public function parseThrowsForStructurallyMalformedHeader(string $header): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('Invalid W3C traceparent header');

        Traceparent::parse($header);
    }

    #[Test]
    public function parseThrowsForUppercaseForbiddenVersion(): void
    {
        // 'FF' is lowercased to 'ff' before the version check and rejected.
        $this->expectException(InvalidIdentifierException::class);

        Traceparent::parse('FF-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01');
    }

    // ---------------------------------------------------------------------
    // Invalid trace id -> dedicated "trace id" rejection
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTraceIdProvider(): iterable
    {
        $span = self::SPAN_ID;

        yield 'too short'  => ["00-abc-{$span}-01"];
        yield 'too long'   => ['00-' . self::TRACE_ID . 'ab' . "-{$span}-01"];
        yield 'non-hex'    => ["00-zzz7651916cd43dd8448eb211c80319c-{$span}-01"];
        yield 'all zero'   => ["00-00000000000000000000000000000000-{$span}-01"];
    }

    #[Test]
    #[DataProvider('invalidTraceIdProvider')]
    public function parseThrowsForInvalidTraceId(string $header): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('Invalid trace id');

        Traceparent::parse($header);
    }

    // ---------------------------------------------------------------------
    // Invalid span id -> dedicated "span id" rejection
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSpanIdProvider(): iterable
    {
        $trace = self::TRACE_ID;

        yield 'too short' => ["00-{$trace}-abc-01"];
        yield 'too long'  => ["00-{$trace}-" . self::SPAN_ID . 'ab' . '-01'];
        yield 'non-hex'   => ["00-{$trace}-zzad6b7169203331-01"];
        yield 'all zero'  => ["00-{$trace}-0000000000000000-01"];
    }

    #[Test]
    #[DataProvider('invalidSpanIdProvider')]
    public function parseThrowsForInvalidSpanId(string $header): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('Invalid span id');

        Traceparent::parse($header);
    }

    // ---------------------------------------------------------------------
    // Validation ordering + exception hierarchy
    // ---------------------------------------------------------------------

    #[Test]
    public function versionCheckTakesPrecedenceOverIdChecks(): void
    {
        // Forbidden version AND an all-zero trace id: the version error wins.
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('Invalid W3C traceparent header');

        Traceparent::parse('ff-00000000000000000000000000000000-' . self::SPAN_ID . '-01');
    }

    #[Test]
    public function flagsCheckTakesPrecedenceOverTraceIdCheck(): void
    {
        // Non-hex flags AND an all-zero trace id: the flags (traceparent) error wins.
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('Invalid W3C traceparent header');

        Traceparent::parse('00-00000000000000000000000000000000-' . self::SPAN_ID . '-zz');
    }

    #[Test]
    public function traceIdCheckTakesPrecedenceOverSpanIdCheck(): void
    {
        // Both ids invalid: the trace id is validated first.
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('Invalid trace id');

        Traceparent::parse('00-00000000000000000000000000000000-0000000000000000-01');
    }

    #[Test]
    public function thrownExceptionIsAnInvalidArgumentException(): void
    {
        try {
            Traceparent::parse('not-a-valid-header');
            self::fail('Expected InvalidIdentifierException was not thrown.');
        } catch (InvalidIdentifierException $e) {
            self::assertInstanceOf(InvalidArgumentException::class, $e);
        }
    }

    #[Test]
    public function malformedHeaderMessagePreservesOriginalCasing(): void
    {
        // The rejection message echoes the raw header argument, un-lowercased.
        try {
            Traceparent::parse('GG-DEADBEEF');
            self::fail('Expected InvalidIdentifierException was not thrown.');
        } catch (InvalidIdentifierException $e) {
            self::assertSame("Invalid W3C traceparent header: 'GG-DEADBEEF'.", $e->getMessage());
        }
    }
}
