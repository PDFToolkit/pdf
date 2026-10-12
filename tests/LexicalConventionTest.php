<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\LexicalConvention\CharacterSet\Character;
use PDF\Syntax\LexicalConvention\EndOfLine;
use PDF\Syntax\LexicalConvention\EndOfLineKind;
use PHPUnit\Framework\TestCase;

final class LexicalConventionTest extends TestCase
{
    public function testEndOfLineSequencesPreserveTheirBytes(): void
    {
        self::assertSame("\n", (string) new EndOfLine());
        self::assertSame("\r", (string) new EndOfLine(EndOfLineKind::CarriageReturn));
        self::assertSame("\r\n", (string) new EndOfLine(EndOfLineKind::CarriageReturnLineFeed));
    }

    public function testCharacterRejectsValuesOutsideAByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Character(256);
    }
}
