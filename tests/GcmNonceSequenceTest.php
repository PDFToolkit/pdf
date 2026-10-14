<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Security\GcmNonceSequence;
use PDF\Security\RandomSource;
use PHPUnit\Framework\TestCase;

final class GcmNonceSequenceTest extends TestCase
{
    public function testEveryInvocationObtainsAllTwelveRandomBytesEvenForReconstructedInstances(): void
    {
        $random = new class implements RandomSource {
            public int $calls = 0;
            public function bytes(int $length): string
            {
                if ($length !== 12) {
                    throw new \LogicException('The complete 96-bit nonce must come from the random source.');
                }
                return str_repeat(chr(++$this->calls), $length);
            }
        };
        $first = new GcmNonceSequence($random);
        self::assertSame(str_repeat("\x01", 12), $first->next());
        self::assertSame(str_repeat("\x02", 12), $first->next());
        self::assertSame(str_repeat("\x03", 12), (new GcmNonceSequence($random))->next());
        self::assertSame(3, $random->calls);
    }

    public function testRandomSourceRepeatsAreRejectedWithoutEncryptingAgain(): void
    {
        $random = new class implements RandomSource {
            public function bytes(int $length): string
            {
                return str_repeat('R', $length);
            }
        };
        $sequence = new GcmNonceSequence($random);
        self::assertSame(str_repeat('R', 12), $sequence->next());
        $this->expectException(\UnexpectedValueException::class);
        $sequence->next();
    }

    public function testExplicitInvocationBudgetFailsBeforeCallingTheRandomSource(): void
    {
        $random = new class implements RandomSource {
            public int $calls = 0;
            public function bytes(int $length): string
            {
                ++$this->calls;
                return str_repeat('R', $length);
            }
        };
        $sequence = new GcmNonceSequence($random, 1);
        $sequence->next();
        try {
            $sequence->next();
            self::fail('The explicit key-scoped invocation budget must be enforced.');
        } catch (\OverflowException) {
            self::assertSame(1, $random->calls);
        }
    }

    public function testCloningDoesNotResetAKeyScopedAllocator(): void
    {
        $sequence = new GcmNonceSequence(new \PDF\Security\SystemRandomSource());
        $this->expectException(\Error::class);
        clone $sequence;
    }

    public function testSerializationCannotRestartAnAllocator(): void
    {
        $sequence = new GcmNonceSequence(new \PDF\Security\SystemRandomSource());
        $this->expectException(\LogicException::class);
        serialize($sequence);
    }
}
