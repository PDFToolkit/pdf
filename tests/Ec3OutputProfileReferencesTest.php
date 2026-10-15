<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Files as F;
use PDF\Specification\Iso32000_2\Catalogue;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Ec3OutputProfileReferencesTest extends TestCase
{
    public function testDeprecatedCaptureTreesAndUnconditionalNodeArraysRetainExactMetadata(): void
    {
        $catalogue = new Catalogue();
        foreach (['IDS', 'URLS'] as $key) {
            self::assertStringContainsString('deprecated in PDF 2.0', $catalogue->dictionary('32')->entry($key)->requirement->wording);
        }
        self::assertStringContainsString('deprecated in PDF 2.0', $catalogue->dictionary('29')->entry('SpiderInfo')->requirement->wording);
        self::assertStringContainsString('shall conform', $catalogue->dictionary('285')->entry('CT')->description);
        foreach (['357', '358'] as $table) {
            self::assertTrue($catalogue->dictionary($table)->entry('Pg')->requirement->conditional);
            self::assertStringContainsString('required when', $catalogue->dictionary($table)->entry('Pg')->description);
        }
        $nodes = $catalogue->dictionary('315')->entry('NA');
        self::assertFalse($nodes->requirement->conditional);
        self::assertStringNotContainsString('only if', $nodes->requirement->wording);
    }

    public function testExternalReferencesAndEmbeddedAlternativesRemainRepresentable(): void
    {
        $embedded = new F\FileSpecification(new LiteralString('profile.icc'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, new F\EmbeddedFileStream('opaque ICC bytes')))));
        $this->validate(new ArrayType(new IndirectReference(self::url('https://example.org/a%20b.icc?q=1#profile')), self::url('../profile.icc'), new IndirectReference($embedded)));
        self::assertTrue(true);
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidExternalReferenceShapeOrEncodingIsRejected(BasicType $references): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validate($references);
    }

    public static function invalidReferences(): iterable
    {
        yield [new ArrayType()];
        yield [new ArrayType(new LiteralString('https://example.org/profile.icc'))];
        yield [new ArrayType(self::url('a b.icc'))];
        yield [new ArrayType(self::url('bad%2'))];
        yield [new ArrayType(self::url('bad%zz'))];
        yield [new ArrayType(self::url("\xfe\xff\x00a"))];
        yield [new ArrayType(self::url("profile\x8e.icc"))];
        yield [new ArrayType(self::url('profile.icc')->without('FS'))];
        yield [new ArrayType(self::url('profile.icc')->with(new Entry(new NameType('FS'), new NameType('DOS'))))];
        yield [new ArrayType(self::url('profile.icc')->with(new Entry(new NameType('F'), new IntegerType(1))))];
        yield [new ArrayType(self::url('profile.icc')->with(new Entry(new NameType('Type'), new NameType('Filespec')))->with(new Entry(new NameType('EF'), new DictionaryType())))];
    }

    private static function url(string $value): DictionaryType
    {
        return new DictionaryType(new Entry(new NameType('FS'), new NameType('URL')), new Entry(new NameType('F'), new LiteralString($value)));
    }

    private function validate(BasicType $references): void
    {
        $dictionary = new DictionaryType(new Entry(new NameType('URLs'), $references));
        foreach ((new Catalogue())->dictionary('402')->semanticRules() as $rule) {
            $rule->validate($dictionary);
        }
    }
}
