<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class PrimitiveTest extends TestCase
{
    public function testNamesEscapeDelimitersAndBinaryBytes(): void
    {
        self::assertSame('/A#20B#23#2F#25#FF', (string) new NameType("A B#/%\xff"));
        self::assertSame('/', (string) new NameType(''));
        $this->expectException(\InvalidArgumentException::class);
        new NameType("a\0b");
    }

    public function testLiteralStringIsBytePreservingAndEscapesSyntax(): void
    {
        self::assertSame('(a\\(b\\)\\\\\\n\\000\\377)', (string) new LiteralString("a(b)\\\n\0\xff"));
        self::assertSame('<ABCD>', (string) new HexadecimalString("\xab\xcd"));
        self::assertSame('<ABC0>', (string) HexadecimalString::fromHex('a b c'));
        self::assertSame('<>', (string) HexadecimalString::fromHex(''));
    }

    public function testNumbersNeverUseExponentOrLocaleNotation(): void
    {
        self::assertSame('0.00000000000000000001', (string) new NumericType(1e-20));
        self::assertSame('-100000000000000000000', (string) new NumericType(-1e20));
        self::assertSame('0', (string) new NumericType(-0.0));
        self::assertSame('1.25', (string) new NumericType(1.25));
        $this->expectException(\InvalidArgumentException::class);
        new NumericType(INF);
    }

    public function testArraysAndDictionariesContainOnlyPdfObjects(): void
    {
        $array = new ArrayType(new IntegerType(3), new BooleanType(false), new NullType());
        self::assertSame('[3 false null]', (string) $array);
        $original = new DictionaryType(new Entry(new NameType('Values'), $array));
        $updated = $original->with(new Entry(new NameType('Flag'), new BooleanType(true)));
        self::assertFalse($original->has('Flag'));
        self::assertTrue($updated->has('Flag'));
        self::assertSame('<< /Values [3 false null] /Flag true >>', (string) $updated);
        self::assertCount(2, $updated);
    }

    public function testDuplicateDictionaryKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DictionaryType(new Entry(new NameType('A'), new IntegerType(1)), new Entry(new NameType('A'), new IntegerType(2)));
    }

    public function testDirectCyclesAreRejected(): void
    {
        $dictionary = new class () extends DictionaryType {
            public function entries(): iterable
            {
                yield new Entry(new NameType('Self'), $this);
            }
        };
        $this->expectException(\LogicException::class);
        (new SerializationContext())->serialize($dictionary);
    }
}
