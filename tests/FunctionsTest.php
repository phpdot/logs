<?php

declare(strict_types=1);

namespace PHPdot\Logs\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;


/**
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class FunctionsTest extends TestCase
{
    #[Test]
    public function encodesAThrowableIntoTheCanonicalShape(): void
    {
        $exception = new RuntimeException('dispatch failed', 42);

        self::assertSame([
            'class'   => RuntimeException::class,
            'message' => 'dispatch failed',
            'code'    => 42,
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ], _e($exception));
    }

    #[Test]
    public function preservesStringCodes(): void
    {
        $exception = new class ('boom') extends RuntimeException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = 'HY000';
            }
        };

        self::assertSame('HY000', _e($exception)['code']);
    }
}
