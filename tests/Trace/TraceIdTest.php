<?php

declare(strict_types=1);

namespace PHPdot\Logs\Tests\Trace;

use InvalidArgumentException;
use PHPdot\Logs\Exception\InvalidIdentifierException;
use PHPdot\Logs\Trace\TraceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TraceIdTest extends TestCase
{
    /**
     * Canonical UUIDv7 string: 8-4-4-4-12 hex with version nibble `7`
     * and W3C/RFC variant nibble in {8,9,a,b}.
     */
    private const string UUID_V7_PATTERN =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    // ---------------------------------------------------------------------
    // generate()
    // ---------------------------------------------------------------------

    #[Test]
    public function generateReturnsTraceIdInstance(): void
    {
        self::assertInstanceOf(TraceId::class, TraceId::generate());
    }

    #[Test]
    public function generateProducesCanonicalUuidV7Format(): void
    {
        self::assertMatchesRegularExpression(self::UUID_V7_PATTERN, TraceId::generate()->uuid());
    }

    #[Test]
    public function generateIdIs32LowercaseHexCharacters(): void
    {
        $id = TraceId::generate()->id();

        self::assertSame(32, strlen($id));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertSame(strtolower($id), $id);
    }

    #[Test]
    public function generateUuidIs36CharactersWithDashes(): void
    {
        $uuid = TraceId::generate()->uuid();

        self::assertSame(36, strlen($uuid));
        self::assertSame(4, substr_count($uuid, '-'));
    }

    #[Test]
    public function generateSetsVersionNibbleToSeven(): void
    {
        // Position 12 of the 32-hex form is the version nibble (RFC 9562 §5.7).
        self::assertSame('7', TraceId::generate()->id()[12]);
    }

    #[Test]
    public function generateSetsVariantBitsToRfc(): void
    {
        // Position 16 holds the variant; high two bits must be 10 → {8,9,a,b}.
        self::assertContains(TraceId::generate()->id()[16], ['8', '9', 'a', 'b']);
    }

    #[Test]
    public function generateEmbedsTimestampMatchingAccessor(): void
    {
        $trace = TraceId::generate();

        // The first 48 bits (12 hex chars) encode the millisecond timestamp.
        self::assertSame((int) hexdec(substr($trace->id(), 0, 12)), $trace->timestamp());
    }

    #[Test]
    public function generateTimestampReflectsCurrentTime(): void
    {
        $before = (int) (microtime(true) * 1000);
        $trace = TraceId::generate();
        $after = (int) (microtime(true) * 1000);

        self::assertGreaterThanOrEqual($before, $trace->timestamp());
        self::assertLessThanOrEqual($after, $trace->timestamp());
    }

    #[Test]
    public function generateProducesUniqueIdsAcrossManyCalls(): void
    {
        $seen = [];

        for ($i = 0; $i < 1000; $i++) {
            $seen[TraceId::generate()->id()] = true;
        }

        self::assertCount(1000, $seen);
    }

    #[Test]
    public function generateProducesTimestampSortableIds(): void
    {
        $first = TraceId::generate();
        usleep(2000); // ensure the embedded millisecond field advances
        $second = TraceId::generate();

        self::assertGreaterThan($first->timestamp(), $second->timestamp());
        // Fixed-width hex prefixes ⇒ lexical order tracks chronological order.
        self::assertLessThan(0, strcmp($first->id(), $second->id()));
    }

    // ---------------------------------------------------------------------
    // id() / uuid() relationship
    // ---------------------------------------------------------------------

    #[Test]
    public function idIsUuidWithoutDashes(): void
    {
        $trace = TraceId::generate();

        self::assertSame(str_replace('-', '', $trace->uuid()), $trace->id());
    }

    #[Test]
    public function accessorsAreStableAcrossRepeatedCalls(): void
    {
        $trace = TraceId::generate();

        self::assertSame($trace->id(), $trace->id());
        self::assertSame($trace->uuid(), $trace->uuid());
        self::assertSame($trace->timestamp(), $trace->timestamp());
    }

    // ---------------------------------------------------------------------
    // fromString() — happy paths
    // ---------------------------------------------------------------------

    #[Test]
    public function fromStringAcceptsUuidFormat(): void
    {
        $uuid = '0188ee45-1234-7abc-89de-0123456789ab';
        $trace = TraceId::fromString($uuid);

        self::assertSame($uuid, $trace->uuid());
        self::assertSame('0188ee4512347abc89de0123456789ab', $trace->id());
    }

    #[Test]
    public function fromStringAcceptsBareHexFormatAndReintroducesDashes(): void
    {
        $hex = '0188ee4512347abc89de0123456789ab';
        $trace = TraceId::fromString($hex);

        self::assertSame($hex, $trace->id());
        self::assertSame('0188ee45-1234-7abc-89de-0123456789ab', $trace->uuid());
    }

    #[Test]
    public function fromStringNormalizesUppercaseToLowercase(): void
    {
        $trace = TraceId::fromString('0188EE45-1234-7ABC-89DE-0123456789AB');

        self::assertSame('0188ee45-1234-7abc-89de-0123456789ab', $trace->uuid());
        self::assertSame('0188ee4512347abc89de0123456789ab', $trace->id());
    }

    #[Test]
    public function fromStringExtractsEmbeddedTimestamp(): void
    {
        // First 12 hex chars "017f22e279b0" == 1645557742000 (independently computed).
        $trace = TraceId::fromString('017f22e279b07cc398c4dc0c0c0c0c0c');

        self::assertSame(1645557742000, $trace->timestamp());
    }

    #[Test]
    public function fromStringRoundTripsAGeneratedId(): void
    {
        $original = TraceId::generate();

        $fromHex = TraceId::fromString($original->id());
        self::assertSame($original->id(), $fromHex->id());
        self::assertSame($original->uuid(), $fromHex->uuid());
        self::assertSame($original->timestamp(), $fromHex->timestamp());

        $fromUuid = TraceId::fromString($original->uuid());
        self::assertSame($original->id(), $fromUuid->id());
        self::assertSame($original->uuid(), $fromUuid->uuid());
        self::assertSame($original->timestamp(), $fromUuid->timestamp());
    }

    #[Test]
    public function fromStringYieldsEqualValuesForIdenticalInput(): void
    {
        $a = TraceId::fromString('0188ee4512347abc89de0123456789ab');
        $b = TraceId::fromString('0188ee4512347abc89de0123456789ab');

        self::assertSame($a->id(), $b->id());
        self::assertSame($a->uuid(), $b->uuid());
        self::assertSame($a->timestamp(), $b->timestamp());
    }

    // ---------------------------------------------------------------------
    // fromString() — error cases
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIdentifierProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'single char' => ['0'];
        yield 'short hex' => ['abc'];
        yield '31 hex chars' => [str_repeat('a', 31)];
        yield '33 hex chars' => [str_repeat('a', 33)];
        yield 'non-hex char g' => [str_repeat('g', 32)];
        yield 'trailing non-hex' => ['0188ee4512347abc89de0123456789az'];
        yield 'leading space' => [' 188ee4512347abc89de0123456789ab'];
        yield 'internal space' => ['0188ee4512347abc89de01234 6789ab'];
        yield 'uuid shape but 31 hex after stripping dashes' => ['0188ee45-1234-7abc-89de-0123456789a'];
    }

    #[Test]
    #[DataProvider('malformedIdentifierProvider')]
    public function fromStringThrowsOnMalformedInput(string $input): void
    {
        $this->expectException(InvalidIdentifierException::class);

        TraceId::fromString($input);
    }

    #[Test]
    public function fromStringThrowsOnAllZeroHexSentinel(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        TraceId::fromString(str_repeat('0', 32));
    }

    #[Test]
    public function fromStringThrowsOnAllZeroUuidSentinel(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        TraceId::fromString('00000000-0000-0000-0000-000000000000');
    }

    #[Test]
    public function thrownExceptionIsAnInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TraceId::fromString('not-a-valid-id');
    }

    #[Test]
    public function thrownExceptionMessageIncludesOffendingValue(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage("got 'zzzzdeadbeef'");

        TraceId::fromString('zzzzdeadbeef');
    }

    // ---------------------------------------------------------------------
    // __toString()
    // ---------------------------------------------------------------------

    #[Test]
    public function toStringReturnsUuidForm(): void
    {
        $trace = TraceId::generate();

        self::assertSame($trace->uuid(), (string) $trace);
        self::assertSame($trace->uuid(), $trace->__toString());
    }

    #[Test]
    public function toStringMatchesUuidAfterParsing(): void
    {
        $trace = TraceId::fromString('0188ee4512347abc89de0123456789ab');

        self::assertSame('0188ee45-1234-7abc-89de-0123456789ab', (string) $trace);
    }

    // ---------------------------------------------------------------------
    // Immutability / construction
    // ---------------------------------------------------------------------

    #[Test]
    public function classIsFinal(): void
    {
        self::assertTrue((new ReflectionClass(TraceId::class))->isFinal());
    }

    #[Test]
    public function constructorIsPrivateSoCreationGoesThroughFactories(): void
    {
        self::assertTrue((new ReflectionClass(TraceId::class))->getConstructor()?->isPrivate());
    }

    #[Test]
    public function allPropertiesAreReadonly(): void
    {
        $properties = (new ReflectionClass(TraceId::class))->getProperties();

        self::assertNotEmpty($properties);

        foreach ($properties as $property) {
            self::assertTrue(
                $property->isReadOnly(),
                "Property \${$property->getName()} must be readonly for immutability.",
            );
        }
    }

    #[Test]
    public function fromStringRejectsTrailingNewline(): void
    {
        // W3C: a trace-id is EXACTLY 32 hex chars, so a value with a trailing
        // newline (33 chars) must be rejected — regression guard for the PCRE "$"
        // anchor (now \z) that previously matched before a trailing newline.
        $this->expectException(InvalidIdentifierException::class);
        TraceId::fromString("0123456789abcdef0123456789abcdef\n");
    }
}
