<?php

declare(strict_types=1);

namespace PHPdot\Logs\Tests\Enum;

use PHPdot\Logs\Enum\SpanKind;
use PHPdot\Logs\Enum\SpanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    // -----------------------------------------------------------------
    // SpanKind — backing values
    // -----------------------------------------------------------------

    #[Test]
    public function spanKindBackingValuesAreTheExactContractTokens(): void
    {
        self::assertSame('internal', SpanKind::Internal->value);
        self::assertSame('server', SpanKind::Server->value);
        self::assertSame('client', SpanKind::Client->value);
        self::assertSame('producer', SpanKind::Producer->value);
        self::assertSame('consumer', SpanKind::Consumer->value);
    }

    #[Test]
    public function spanKindHasExactlyFiveCases(): void
    {
        $cases = SpanKind::cases();

        self::assertCount(5, $cases);
        self::assertSame(
            [
                SpanKind::Internal,
                SpanKind::Server,
                SpanKind::Client,
                SpanKind::Producer,
                SpanKind::Consumer,
            ],
            $cases,
        );
    }

    #[Test]
    public function spanKindBackingValuesAreUniqueAndLowercase(): void
    {
        $values = array_map(static fn(SpanKind $k): string => $k->value, SpanKind::cases());

        self::assertSame($values, array_values(array_unique($values)));
        foreach ($values as $value) {
            self::assertSame(strtolower($value), $value);
        }
    }

    // -----------------------------------------------------------------
    // SpanKind::fromString — happy paths
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{string, SpanKind}>
     */
    public static function spanKindExactTokens(): iterable
    {
        yield 'internal' => ['internal', SpanKind::Internal];
        yield 'server' => ['server', SpanKind::Server];
        yield 'client' => ['client', SpanKind::Client];
        yield 'producer' => ['producer', SpanKind::Producer];
        yield 'consumer' => ['consumer', SpanKind::Consumer];
    }

    #[Test]
    #[DataProvider('spanKindExactTokens')]
    public function spanKindFromStringResolvesExactTokens(string $token, SpanKind $expected): void
    {
        self::assertSame($expected, SpanKind::fromString($token));
    }

    #[Test]
    public function spanKindFromStringMatchesTryFromForEveryCase(): void
    {
        foreach (SpanKind::cases() as $case) {
            self::assertSame($case, SpanKind::fromString($case->value));
        }
    }

    #[Test]
    public function spanKindFromStringIsCaseInsensitive(): void
    {
        self::assertSame(SpanKind::Server, SpanKind::fromString('SERVER'));
        self::assertSame(SpanKind::Client, SpanKind::fromString('Client'));
        self::assertSame(SpanKind::Producer, SpanKind::fromString('PrOdUcEr'));
        self::assertSame(SpanKind::Consumer, SpanKind::fromString('CONSUMER'));
        self::assertSame(SpanKind::Internal, SpanKind::fromString('Internal'));
    }

    // -----------------------------------------------------------------
    // SpanKind::fromString — unknown-input fallback
    // -----------------------------------------------------------------

    #[Test]
    public function spanKindFromStringFallsBackToInternalForUnknownToken(): void
    {
        self::assertSame(SpanKind::Internal, SpanKind::fromString('bogus'));
    }

    #[Test]
    public function spanKindFromStringFallsBackToInternalForEmptyString(): void
    {
        self::assertSame(SpanKind::Internal, SpanKind::fromString(''));
    }

    #[Test]
    public function spanKindFromStringDoesNotTrimWhitespaceAndFallsBack(): void
    {
        // strtolower is applied, but surrounding whitespace is not trimmed,
        // so a padded token is unknown and falls back to Internal.
        self::assertSame(SpanKind::Internal, SpanKind::fromString(' server'));
        self::assertSame(SpanKind::Internal, SpanKind::fromString('server '));
        self::assertSame(SpanKind::Internal, SpanKind::fromString("\tclient\n"));
    }

    #[Test]
    public function spanKindFromStringFallsBackForNumericAndSymbolTokens(): void
    {
        self::assertSame(SpanKind::Internal, SpanKind::fromString('0'));
        self::assertSame(SpanKind::Internal, SpanKind::fromString('SERVER!'));
        self::assertSame(SpanKind::Internal, SpanKind::fromString('span.kind'));
    }

    #[Test]
    public function spanKindTryFromRejectsUppercaseToken(): void
    {
        // Sanity check that the tolerance lives in fromString, not in the enum
        // backing: the raw token is lowercase only.
        self::assertNull(SpanKind::tryFrom('SERVER'));
        self::assertSame(SpanKind::Server, SpanKind::from('server'));
    }

    // -----------------------------------------------------------------
    // SpanStatus — backing values
    // -----------------------------------------------------------------

    #[Test]
    public function spanStatusBackingValuesAreTheExactContractTokens(): void
    {
        self::assertSame('unset', SpanStatus::Unset->value);
        self::assertSame('ok', SpanStatus::Ok->value);
        self::assertSame('error', SpanStatus::Error->value);
    }

    #[Test]
    public function spanStatusHasExactlyThreeCases(): void
    {
        $cases = SpanStatus::cases();

        self::assertCount(3, $cases);
        self::assertSame(
            [
                SpanStatus::Unset,
                SpanStatus::Ok,
                SpanStatus::Error,
            ],
            $cases,
        );
    }

    #[Test]
    public function spanStatusBackingValuesAreUniqueAndLowercase(): void
    {
        $values = array_map(static fn(SpanStatus $s): string => $s->value, SpanStatus::cases());

        self::assertSame($values, array_values(array_unique($values)));
        foreach ($values as $value) {
            self::assertSame(strtolower($value), $value);
        }
    }

    // -----------------------------------------------------------------
    // SpanStatus::fromString — happy paths
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{string, SpanStatus}>
     */
    public static function spanStatusExactTokens(): iterable
    {
        yield 'unset' => ['unset', SpanStatus::Unset];
        yield 'ok' => ['ok', SpanStatus::Ok];
        yield 'error' => ['error', SpanStatus::Error];
    }

    #[Test]
    #[DataProvider('spanStatusExactTokens')]
    public function spanStatusFromStringResolvesExactTokens(string $token, SpanStatus $expected): void
    {
        self::assertSame($expected, SpanStatus::fromString($token));
    }

    #[Test]
    public function spanStatusFromStringMatchesTryFromForEveryCase(): void
    {
        foreach (SpanStatus::cases() as $case) {
            self::assertSame($case, SpanStatus::fromString($case->value));
        }
    }

    #[Test]
    public function spanStatusFromStringIsCaseInsensitive(): void
    {
        self::assertSame(SpanStatus::Ok, SpanStatus::fromString('OK'));
        self::assertSame(SpanStatus::Error, SpanStatus::fromString('ERROR'));
        self::assertSame(SpanStatus::Error, SpanStatus::fromString('Error'));
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString('UNSET'));
        self::assertSame(SpanStatus::Ok, SpanStatus::fromString('Ok'));
    }

    // -----------------------------------------------------------------
    // SpanStatus::fromString — unknown-input fallback
    // -----------------------------------------------------------------

    #[Test]
    public function spanStatusFromStringFallsBackToUnsetForUnknownToken(): void
    {
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString('bogus'));
    }

    #[Test]
    public function spanStatusFromStringFallsBackToUnsetForEmptyString(): void
    {
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString(''));
    }

    #[Test]
    public function spanStatusFromStringDoesNotTrimWhitespaceAndFallsBack(): void
    {
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString(' ok'));
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString('ok '));
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString("\terror\n"));
    }

    #[Test]
    public function spanStatusFromStringDoesNotConfuseSuccessSynonyms(): void
    {
        // Only the exact tokens map; common synonyms are unknown and fall back.
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString('success'));
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString('failed'));
        self::assertSame(SpanStatus::Unset, SpanStatus::fromString('failure'));
    }

    #[Test]
    public function spanStatusTryFromRejectsUppercaseToken(): void
    {
        self::assertNull(SpanStatus::tryFrom('OK'));
        self::assertSame(SpanStatus::Error, SpanStatus::from('error'));
    }

    // -----------------------------------------------------------------
    // Cross-cutting: the two enums are distinct, independent types
    // -----------------------------------------------------------------

    #[Test]
    public function fromStringReturnTypesAreTheCorrectEnum(): void
    {
        self::assertInstanceOf(SpanKind::class, SpanKind::fromString('whatever'));
        self::assertInstanceOf(SpanStatus::class, SpanStatus::fromString('whatever'));
    }
}
