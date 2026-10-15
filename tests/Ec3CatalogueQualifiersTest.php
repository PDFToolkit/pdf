<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Iso32000_2\Catalogue;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Source identities and scope are retained in ec3-semantic-reviews.json. */
final class Ec3CatalogueQualifiersTest extends TestCase
{
    public function testOptionalFieldNameAndSignatureDigestSerializeWithoutInventedValues(): void
    {
        self::assertSame('<<>>', $this->dictionary('226')->toPdf(new SerializationContext()));
        $signatureReference = $this->dictionary('256', new Entry(new NameType('TransformMethod'), new NameType('DocMDP')));
        self::assertStringNotContainsString('/DigestMethod', $signatureReference->toPdf(new SerializationContext()));
        self::assertStringContainsString('deprecated in PDF 2.0', $signatureReference->definition()->entry('DigestMethod')->requirement->wording);
    }

    #[DataProvider('unconditionalKeys')]
    public function testCorrectedRequiredKeysAreEnforced(string $table, string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/' . $key . ' is missing');
        $entries = $table === '254' ? [new Entry(new NameType('Subtype'), new NameType('Text')), new Entry(new NameType('Rect'), new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)))] : [];
        $this->dictionary($table, ...$entries)->validate();
    }

    public static function unconditionalKeys(): iterable
    {
        yield ['255', 'Filter'];
        yield ['254', 'Page'];
    }

    public function testActionsAndSignaturesDoNotInheritUnrelatedEntries(): void
    {
        $catalogue = new Catalogue();
        foreach (['239' => ['Flags', 'CharSet'], '241' => ['Flags'], '255' => ['Filter']] as $table => $keys) {
            foreach ($keys as $key) {
                self::assertFalse($catalogue->dictionary((string) $table)->entry($key)->requirement->inheritable);
            }
        }
        self::assertTrue($catalogue->dictionary('228')->entry('DA')->requirement->inheritable);
    }

    public function testByteStringsPreserveRawAppearanceAndChecksumBytes(): void
    {
        $catalogue = new Catalogue();
        foreach (['45' => 'CheckSum', '177' => 'DA', '224' => 'DA', '228' => 'DA'] as $table => $key) {
            $entry = $catalogue->dictionary((string) $table)->entry($key);
            self::assertSame('byte string', $entry->type->specificationType);
            $entry->type->validate(new LiteralString("\x00\x80\xff"));
        }
    }

    public function testVersionAndDeprecationMetadataAreEditionSpecific(): void
    {
        $catalogue = new Catalogue();
        foreach (['261', '262'] as $table) {
            foreach ($catalogue->dictionary($table)->entries() as $entry) {
                self::assertSame('2.0', $entry->requirement->introducedIn);
            }
        }
        foreach (['176', '177'] as $table) {
            self::assertSame('1.3', $catalogue->dictionary($table)->entry('BS')->requirement->introducedIn);
        }
        foreach (['31' => 'ID', '87' => 'ID', '311' => 'Resources', '355' => 'R'] as $table => $key) {
            self::assertStringContainsString('deprecated in PDF 2.0', $catalogue->dictionary((string) $table)->entry($key)->requirement->wording);
        }
        self::assertStringContainsString('2 recognises PDF 1.7', $catalogue->dictionary('237')->entry('V')->description);
    }

    public function testRectangleDifferencesAreArraysAndNotCoordinateRectangles(): void
    {
        $catalogue = new Catalogue();
        $differences = new ArrayType(new IntegerType(4), new IntegerType(2), new IntegerType(1), new IntegerType(0));
        foreach (['177', '180', '183'] as $table) {
            self::assertSame('array', $catalogue->dictionary($table)->entry('RD')->type->specificationType);
            $catalogue->dictionary($table)->entry('RD')->type->validate($differences);
            $this->relations($table, new DictionaryType(new Entry(new NameType('RD'), $differences)));
        }
    }

    #[DataProvider('invalidDifferences')]
    public function testMalformedRectangleDifferencesAreRejected(string $table, ArrayType $differences): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->relations($table, new DictionaryType(new Entry(new NameType('RD'), $differences)));
    }

    public static function invalidDifferences(): iterable
    {
        foreach (['177', '180', '183'] as $table) {
            yield [$table, new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0))];
            yield [$table, new ArrayType(new IntegerType(0), new IntegerType(-1), new IntegerType(0), new IntegerType(0))];
        }
    }

    public function testDigestNamesAreUnorderedAndMayBeIndirect(): void
    {
        foreach ([['SHA256', 'SHA512'], ['SHA512', 'SHA256'], ['SHA256', 'SHA256'], []] as $names) {
            $array = new ArrayType(...array_map(fn (string $name): IndirectReference => new IndirectReference(new NameType($name)), $names));
            $value = $this->dictionary('237', new Entry(new NameType('DigestMethod'), $array));
            self::assertStringContainsString('/DigestMethod', $value->toPdf(new SerializationContext()));
        }
    }

    public function testSecurityMetadataPreservesHandlerUnitsAndSubfilterVersions(): void
    {
        $catalogue = new Catalogue();
        self::assertStringContainsString('128 for public-key security handlers, and 16 for the standard security handler', $catalogue->dictionary('25')->entry('Length')->description);
        self::assertStringContainsString('256 for public-key security handlers, and 32 for the standard security handler', $catalogue->dictionary('25')->entry('Length')->description);
        self::assertStringContainsString('adbe.pkcs7.detached (PDF 1.3)', $catalogue->dictionary('255')->entry('SubFilter')->description);
        self::assertStringContainsString('adbe.pkcs7.sha1 (PDF 1.4)', $catalogue->dictionary('255')->entry('SubFilter')->description);
        self::assertSame('byte string or array', $catalogue->dictionary('27')->entry('Recipients')->type->specificationType);
        $catalogue->dictionary('27')->entry('Recipients')->type->validate(new LiteralString("\x80\x00"));
        $catalogue->dictionary('27')->entry('Recipients')->type->validate(new ArrayType(new LiteralString("\x80\x00")));
        $this->expectException(\InvalidArgumentException::class);
        $catalogue->dictionary('27')->entry('Recipients')->type->validate(new ArrayType(new IntegerType(1)));
    }

    public function testZeroMaxLenIsValidAndNegativeMaxLenIsRejected(): void
    {
        $this->relations('232', new DictionaryType(new Entry(new NameType('MaxLen'), new IntegerType(0))));
        $this->expectException(\InvalidArgumentException::class);
        $this->relations('232', new DictionaryType(new Entry(new NameType('MaxLen'), new IntegerType(-1))));
    }

    #[DataProvider('invalidDigests')]
    public function testInvalidDigestNamesAreRejected(ArrayType $array): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dictionary('237', new Entry(new NameType('DigestMethod'), $array))->validate();
    }

    public static function invalidDigests(): iterable
    {
        yield [new ArrayType(new NameType('MD5'))];
        yield [new ArrayType(new NameType('sha256'))];
        yield [new ArrayType(new LiteralString('SHA256'))];
    }

    private function dictionary(string $table, Entry ...$entries): SchemaDictionary
    {
        return new class ((new Catalogue())->dictionary($table), ...$entries) extends SchemaDictionary {};
    }

    private function relations(string $table, DictionaryType $dictionary): void
    {
        foreach ((new Catalogue())->dictionary($table)->semanticRules() as $rule) {
            $rule->validate($dictionary);
        }
    }
}
