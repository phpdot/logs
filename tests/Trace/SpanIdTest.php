<?php

declare(strict_types=1);

namespace PHPdot\Logs\Tests\Trace;

use InvalidArgumentException;
use PHPdot\Logs\Exception\InvalidIdentifierException;
use PHPdot\Logs\Trace\SpanId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpanIdTest extends TestCase
{
    private const string HEX16 = '/^[0-9a-f]{16}$/';

    // ---------------------------------------------------------------------
    // generate()
    // ---------------------------------------------------------------------

    #[Test]
    public function generateReturnsSpanIdInstance(): void
    {
        self::assertInstanceOf(SpanId::class, SpanId::generate());
    }

    #[Test]
    public function generateProducesSixteenHexCharacters(): void
    {
        $id = SpanId::generate()->id();

        self::assertSame(16, \strlen($id));
        self::assertMatchesRegularExpression(self::HEX16, $id);
    }

    #[Test]
    public function generateProducesLowercaseHexOnly(): void
    {
        // 8 bytes of entropy => exactly 16 lowercase hex chars per W3C span-id.
        $id = SpanId::generate()->id();

        self::assertSame(strtolower($id), $id);
        self::assertSame(0, preg_match('/[^0-9a-f]/', $id));
    }

    #[Test]
    public function generateNeverProducesAllZeroSentinel(): void
    {
        // The all-zero id is the W3C "invalid" sentinel and must be re-rolled.
        for ($i = 0; $i < 5000; $i++) {
            self::assertNotSame('0000000000000000', SpanId::generate()->id());
        }
    }

    #[Test]
    public function generateProducesUniqueIds(): void
    {
        $seen = [];

        for ($i = 0; $i < 5000; $i++) {
            $seen[SpanId::generate()->id()] = true;
        }

        self::assertCount(5000, $seen);
    }

    #[Test]
    public function generatedIdIsAcceptedByFromString(): void
    {
        $generated = SpanId::generate();
        $parsed    = SpanId::fromString($generated->id());

        self::assertSame($generated->id(), $parsed->id());
    }

    // ---------------------------------------------------------------------
    // fromString() — happy paths
    // ---------------------------------------------------------------------

    #[Test]
    public function fromStringAcceptsValidLowercaseHex(): void
    {
        $id = SpanId::fromString('0123456789abcdef');

        self::assertSame('0123456789abcdef', $id->id());
    }

    #[Test]
    public function fromStringLowercasesUppercaseInput(): void
    {
        $id = SpanId::fromString('0123456789ABCDEF');

        self::assertSame('0123456789abcdef', $id->id());
    }

    #[Test]
    public function fromStringAcceptsMixedCaseInput(): void
    {
        $id = SpanId::fromString('00AbCdEf12345678');

        self::assertSame('00abcdef12345678', $id->id());
    }

    #[Test]
    #[DataProvider('validBoundaryProvider')]
    public function fromStringAcceptsBoundaryValues(string $input, string $expected): void
    {
        self::assertSame($expected, SpanId::fromString($input)->id());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validBoundaryProvider(): iterable
    {
        yield 'smallest non-zero' => ['0000000000000001', '0000000000000001'];
        yield 'largest'           => ['ffffffffffffffff', 'ffffffffffffffff'];
        yield 'largest uppercase' => ['FFFFFFFFFFFFFFFF', 'ffffffffffffffff'];
        yield 'all f mixed case'  => ['FfFfFfFfFfFfFfFf', 'ffffffffffffffff'];
        yield 'single set bit'    => ['8000000000000000', '8000000000000000'];
    }

    // ---------------------------------------------------------------------
    // fromString() — validation / exceptions
    // ---------------------------------------------------------------------

    #[Test]
    #[DataProvider('invalidInputProvider')]
    public function fromStringThrowsOnInvalidInput(string $input): void
    {
        $this->expectException(InvalidIdentifierException::class);

        SpanId::fromString($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'empty string'                 => [''];
        yield 'one char short (15)'          => ['0123456789abcde'];
        yield 'one char too long (17)'       => ['0123456789abcdef0'];
        yield 'far too long'                 => ['0123456789abcdef0123456789abcdef'];
        yield 'non-hex letter g'             => ['0123456789abcdeg'];
        yield 'non-hex letter z'             => ['zzzzzzzzzzzzzzzz'];
        yield 'uppercase non-hex G'          => ['0123456789ABCDEG'];
        yield 'leading space'                => [' 123456789abcdef'];
        yield 'trailing space (15 hex)'      => ['0123456789abcde '];
        yield 'sixteen hex + trailing space' => ['0123456789abcdef '];
        yield 'interior space'               => ['01234 6789abcdef'];
        yield 'punctuation'                  => ['0123456789abcde!'];
        yield 'hex prefix 0x'               => ['0x0123456789abcd'];
        yield 'multibyte char'               => ['0123456789abcdeé'];
        yield 'all zero sentinel'            => ['0000000000000000'];
        yield 'whitespace only'             => ['                '];
    }

    #[Test]
    public function fromStringRejectsAllZeroSentinel(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage("got '0000000000000000'");

        SpanId::fromString('0000000000000000');
    }

    #[Test]
    public function thrownExceptionIsAnInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SpanId::fromString('not-a-valid-id!!');
    }

    #[Test]
    public function exceptionMessageContainsOffendingValue(): void
    {
        try {
            SpanId::fromString('zzzzzzzzzzzzzzzz');
            self::fail('Expected InvalidIdentifierException was not thrown.');
        } catch (InvalidIdentifierException $e) {
            self::assertStringContainsString('zzzzzzzzzzzzzzzz', $e->getMessage());
            self::assertStringContainsString('span id', $e->getMessage());
        }
    }

    #[Test]
    public function exceptionMessageShowsLowercasedOffendingValue(): void
    {
        // fromString lowercases before validating, so the reported value is
        // the lowercased form — here lowercasing exposes the invalid 'g'.
        try {
            SpanId::fromString('0123456789ABCDEG');
            self::fail('Expected InvalidIdentifierException was not thrown.');
        } catch (InvalidIdentifierException $e) {
            self::assertStringContainsString('0123456789abcdeg', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // id() / __toString() / round trip
    // ---------------------------------------------------------------------

    #[Test]
    public function toStringEqualsId(): void
    {
        $id = SpanId::fromString('0123456789abcdef');

        self::assertSame($id->id(), $id->__toString());
        self::assertSame($id->id(), (string) $id);
    }

    #[Test]
    public function castToStringYieldsSixteenHexChars(): void
    {
        $cast = (string) SpanId::generate();

        self::assertMatchesRegularExpression(self::HEX16, $cast);
    }

    #[Test]
    public function roundTripsThroughToStringAndFromString(): void
    {
        $original  = SpanId::generate();
        $roundTrip = SpanId::fromString((string) $original);

        self::assertSame((string) $original, (string) $roundTrip);
        self::assertSame($original->id(), $roundTrip->id());
    }

    #[Test]
    public function fromStringIsIdempotentAcrossRepeatedParsing(): void
    {
        $first  = SpanId::fromString('00ABCDEF12345678');
        $second = SpanId::fromString($first->id());

        self::assertSame('00abcdef12345678', $second->id());
    }

    // ---------------------------------------------------------------------
    // Regression guard for a fixed bug (trailing-newline / PCRE anchor).
    // ---------------------------------------------------------------------

    #[Test]
    public function fromStringRejectsTrailingNewline(): void
    {
        // W3C: a span-id must be EXACTLY 16 hex chars, so a value with a trailing
        // newline (17 chars) must be rejected — regression guard for the PCRE "$"
        // anchor (now \z) that previously matched before a trailing newline.
        $this->expectException(InvalidIdentifierException::class);
        SpanId::fromString("0123456789abcdef\n");
    }
}
