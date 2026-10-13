<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\OutlineDictionary;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentDictionaryValidationTest extends TestCase
{
    /** @return iterable<string, array{string, BasicType}> */
    public static function invalidCatalogValues(): iterable
    {
        yield 'direct destinations' => ['Dests', new DictionaryType()];
        yield 'direct outlines' => ['Outlines', new OutlineDictionary()];
        yield 'direct threads' => ['Threads', new ArrayType()];
        yield 'destinations reference with array target' => ['Dests', new IndirectReference(new ArrayType())];
        yield 'outlines reference with array target' => ['Outlines', new IndirectReference(new ArrayType())];
        yield 'threads reference with dictionary target' => ['Threads', new IndirectReference(new DictionaryType())];
        yield 'numeric language' => ['Lang', new NumericType(12)];
    }

    #[DataProvider('invalidCatalogValues')]
    public function testCatalogConstructorValidatesGenericKnownEntries(string $key, BasicType $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DocumentCatalog(new PageTreeNode(), new Entry(new NameType($key), $value));
    }

    #[DataProvider('invalidCatalogValues')]
    public function testCatalogWithValidatesGenericKnownEntries(string $key, BasicType $value): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $this->expectException(\InvalidArgumentException::class);
        try {
            $catalog->with(new Entry(new NameType($key), $value));
        } finally {
            self::assertFalse($catalog->has($key));
        }
    }

    /** @return iterable<string, array{string, IndirectReference}> */
    public static function validIndirectCatalogValues(): iterable
    {
        yield 'destinations' => ['Dests', new IndirectReference(new DictionaryType())];
        yield 'outlines' => ['Outlines', new IndirectReference(new OutlineDictionary())];
        yield 'threads' => ['Threads', new IndirectReference(new ArrayType())];
    }

    #[DataProvider('validIndirectCatalogValues')]
    public function testCatalogReferencesPreserveIdentityAndSerializeIndirectly(string $key, IndirectReference $reference): void
    {
        $entry = new Entry(new NameType($key), $reference);
        $original = new DocumentCatalog(new PageTreeNode());
        $copy = $original->with($entry);
        $constructed = new DocumentCatalog(new PageTreeNode(), $entry);

        self::assertNotSame($original, $copy);
        self::assertFalse($original->has($key));
        self::assertSame($reference, $copy->get($key));
        self::assertSame($reference, $constructed->get($key));
        $pdf = (new FileStructure(new FileHeader(), new FileBody($copy)))->toPdf();
        self::assertMatchesRegularExpression('~/' . $key . ' [1-9][0-9]* 0 R~', $pdf);
    }

    /** @return iterable<string, array{string, BasicType}> */
    public static function invalidResourceValues(): iterable
    {
        yield 'numeric font dictionary' => ['Font', new NumericType(12)];
        yield 'font reference with numeric target' => ['Font', new IndirectReference(new NumericType(12))];
        yield 'dictionary procedure sets' => ['ProcSet', new DictionaryType()];
        yield 'procedure sets reference with dictionary target' => ['ProcSet', new IndirectReference(new DictionaryType())];
    }

    #[DataProvider('invalidResourceValues')]
    public function testResourcesConstructorValidatesGenericKnownEntries(string $key, BasicType $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResourceDictionary(new Entry(new NameType($key), $value));
    }

    #[DataProvider('invalidResourceValues')]
    public function testResourcesWithRejectsAnInvalidReplacement(string $key, BasicType $value): void
    {
        $existing = $key === 'ProcSet' ? new ArrayType(new NameType('PDF')) : new DictionaryType();
        $resources = new ResourceDictionary(new Entry(new NameType($key), $existing));
        $this->expectException(\InvalidArgumentException::class);
        try {
            (void) $resources->with(new Entry(new NameType($key), $value));
        } finally {
            self::assertSame($existing, $resources->get($key));
        }
    }

    /** @return iterable<string, array{string, IndirectReference}> */
    public static function validIndirectResourceValues(): iterable
    {
        yield 'font dictionary' => ['Font', new IndirectReference(new DictionaryType())];
        yield 'procedure sets' => ['ProcSet', new IndirectReference(new ArrayType(new NameType('PDF')))];
    }

    #[DataProvider('validIndirectResourceValues')]
    public function testResourcesReferencesPreserveIdentityAndImmutability(string $key, IndirectReference $reference): void
    {
        $entry = new Entry(new NameType($key), $reference);
        $original = new ResourceDictionary();
        $copy = $original->with($entry);
        $constructed = new ResourceDictionary($entry);

        self::assertNotSame($original, $copy);
        self::assertFalse($original->has($key));
        self::assertSame($reference, $copy->get($key));
        self::assertSame($reference, $constructed->get($key));
        self::assertMatchesRegularExpression('~/' . $key . ' [1-9][0-9]* 0 R~', (string) $copy);
    }

    public function testUnknownGenericExtensionsRemainAvailable(): void
    {
        $value = new IntegerType(42);
        $entry = new Entry(new NameType('RoukmouteCustom'), $value);
        $catalog = new DocumentCatalog(new PageTreeNode(), $entry);
        $resources = new ResourceDictionary($entry);

        self::assertSame($value, $catalog->get('RoukmouteCustom'));
        self::assertSame($value, $resources->get('RoukmouteCustom'));
        $originalCatalog = new DocumentCatalog(new PageTreeNode());
        $originalResources = new ResourceDictionary();
        self::assertSame($value, $originalCatalog->with($entry)->get('RoukmouteCustom'));
        self::assertSame($value, $originalResources->with($entry)->get('RoukmouteCustom'));
        self::assertFalse($originalCatalog->has('RoukmouteCustom'));
        self::assertFalse($originalResources->has('RoukmouteCustom'));
    }
}
