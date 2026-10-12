<?php

declare(strict_types=1);

namespace PDF\Tests\Syntax\LexicalConvention\CharacterSet;

use PDF\Syntax\LexicalConvention\CharacterSet\WhiteSpace;
use PDF\Syntax\LexicalConvention\CharacterSet\WhiteSpaceKind;
use PHPUnit\Framework\TestCase;

final class WhiteSpaceTest extends TestCase
{
    public function testEachNormativeCharacterIsAnObject(): void
    {
        $expected = [0, 9, 10, 12, 13, 32];
        foreach (WhiteSpaceKind::cases() as $index => $kind) {
            $character = new WhiteSpace($kind);
            self::assertSame($expected[$index], $character->byteValue);
            self::assertSame(chr($expected[$index]), (string) $character);
        }
    }
}
