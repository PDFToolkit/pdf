<?php

declare(strict_types=1);

namespace PDF\Tests\Syntax\LexicalConvention\CharacterSet;

use PDF\Syntax\LexicalConvention\CharacterSet\Delimiter;
use PDF\Syntax\LexicalConvention\CharacterSet\DelimiterKind;
use PHPUnit\Framework\TestCase;

final class DelimiterTest extends TestCase
{
    public function testEachNormativeCharacterIsAnObject(): void
    {
        $expected = [40, 41, 60, 62, 91, 93, 123, 125, 47, 37];
        foreach (DelimiterKind::cases() as $index => $kind) {
            $character = new Delimiter($kind);
            self::assertSame($expected[$index], $character->byteValue);
            self::assertSame(chr($expected[$index]), (string) $character);
        }
    }
}
