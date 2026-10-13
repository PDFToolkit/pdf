<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Destinations\Fit;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\TestCase;

final class HybridReferenceTest extends TestCase
{
    public function testInitialFallbackKeepsPagesResourcesAndCompleteFontsVisible(): void
    {
        [$file, $font, $widths, $descriptor, $outline, $container] = $this->document();
        $before = $file->toPdf();
        $revision = (new FileWriter())->revision($file);
        self::assertSame($before, $file->toPdf());
        self::assertSame(CrossReferenceFormat::Hybrid, $revision->crossReferenceFormat);
        foreach ([$font, $widths, $descriptor] as $value) {
            self::assertGreaterThan(0, $revision->span($value)->length);
        }
        $binding = $revision->binding($container);
        self::assertInstanceOf(ObjectStream::class, $binding->value);
        self::assertNotSame($container, $binding->value);
        self::assertCount(1, $binding->value->embeddedEntries());
        self::assertSame($outline, $binding->value->embeddedEntries()[0]->value);
        self::assertCount(8, $container->embeddedEntries());
        preg_match_all('/xref\n(\d+) (\d+)\n((?:\d{10} \d{5} [nf] \n)+)trailer\n(<<.*?>>)/s', $revision->bytes, $sections, PREG_SET_ORDER);
        self::assertCount(2, $sections);
        self::assertStringNotContainsString('/XRefStm', $sections[0][4]);
        self::assertStringContainsString('/XRefStm ', $sections[1][4]);
        $records = explode("\n", trim($sections[0][3]));
        $outlineNumber = $revision->binding($outline)->identifier->objectNumber->value;
        self::assertStringContainsString('65535 f', $records[$outlineNumber]);
        self::assertSame($sections[1][0], substr($revision->bytes, $revision->crossReferenceOffset->value, strlen($sections[1][0])));
    }

    public function testAppendRetainsAliasesAndPrefixesWhileUsingASeparateHybridUpdate(): void
    {
        [$file, $font, , , $outline] = $this->document();
        $initial = (new FileWriter())->revision($file);
        $newOptional = new DictionaryType(new Entry(new NameType('Private'), new IntegerType(7)));
        $nextNumber = new ObjectNumber($initial->size->integer() + 2);
        $packed = new ObjectStream(new ObjectStreamEntry($nextNumber, $newOptional), new FlateDecode());
        $update = (new IncrementalUpdate($initial, new FileBody($packed)))->toRevision();
        self::assertStringStartsWith($initial->bytes, $update->bytes);
        self::assertSame($initial->binding($font)->identifier, $update->binding($font)->identifier);
        self::assertSame($initial->binding($outline)->identifier, $update->binding($outline)->identifier);
        self::assertSame($nextNumber->value, $update->binding($newOptional)->identifier->objectNumber->value);
        self::assertSame(2, substr_count(substr($update->bytes, strlen($initial->bytes)), "\nxref\n"));
        self::assertSame(1, substr_count(substr($update->bytes, strlen($initial->bytes)), '/XRefStm '));
        $again = (new IncrementalUpdate($update, new FileBody(new IndirectObject($newOptional->with(new Entry(new NameType('Private'), new IntegerType(8))), $update->binding($newOptional)->identifier))))->toRevision();
        self::assertStringStartsWith($update->bytes, $again->bytes);
        self::assertGreaterThan(0, $again->span($newOptional)->length);
        self::assertSame(8, $again->binding($newOptional)->dictionary?->get('Private')?->value);
    }

    public function testAFormerOptionalCompressedValueBecomesVisibleWhenRequiredByAnArray(): void
    {
        [$file] = $this->document();
        $optional = new DictionaryType(new Entry(new NameType('Value'), new IntegerType(9)));
        $container = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(200), $optional));
        $file = new FileStructure($file->header, new FileBody(...[...$file->body->objects(), $container]), $file->root, $file->trailerEntries, CrossReferenceFormat::Hybrid);
        $initial = (new FileWriter())->revision($file);
        $catalog = $initial->binding($initial->root->target())->dictionary;
        self::assertNotNull($catalog);
        $catalog = $catalog->with(new Entry(new NameType('PrivateRequiredArray'), new ArrayType($initial->reference($optional))));
        $update = (new IncrementalUpdate($initial, new FileBody(new IndirectObject($catalog, $initial->binding($initial->root->target())->identifier))))->toRevision();
        self::assertGreaterThan(0, $update->span($optional)->length);
        self::assertSame(200, $update->binding($optional)->identifier->objectNumber->value);
        self::assertStringContainsString("200 0 obj\n<< /Value 9 >>", substr($update->bytes, strlen($initial->bytes)));
    }

    /** @return array{FileStructure, DictionaryType, ArrayType, DictionaryType, OutlineDictionary, ObjectStream} */
    private function document(): array
    {
        $widths = new ArrayType(...array_fill(0, 256, new IntegerType(500)));
        $descriptor = new DictionaryType(new Entry(new NameType('Type'), new NameType('FontDescriptor')), new Entry(new NameType('FontName'), new NameType('Helvetica')), new Entry(new NameType('Flags'), new IntegerType(32)), new Entry(new NameType('FontBBox'), new Rectangle(new IntegerType(0), new IntegerType(-207), new IntegerType(1000), new IntegerType(718))), new Entry(new NameType('ItalicAngle'), new IntegerType(0)), new Entry(new NameType('Ascent'), new IntegerType(718)), new Entry(new NameType('Descent'), new IntegerType(-207)), new Entry(new NameType('CapHeight'), new IntegerType(718)), new Entry(new NameType('StemV'), new IntegerType(88)));
        $font = new DictionaryType(new Entry(new NameType('Type'), new NameType('Font')), new Entry(new NameType('Subtype'), new NameType('Type1')), new Entry(new NameType('BaseFont'), new NameType('Helvetica')), new Entry(new NameType('FirstChar'), new IntegerType(0)), new Entry(new NameType('LastChar'), new IntegerType(255)), new Entry(new NameType('Widths'), new IndirectReference($widths)), new Entry(new NameType('FontDescriptor'), new IndirectReference($descriptor)));
        $resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $pages = new PageTreeNode();
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(300)), new IndirectReference($resources), new StreamObject('BT /F1 12 Tf 20 250 Td (Hybrid) Tj ET'));
        $pages->addKid($page);
        $outline = new OutlineDictionary();
        $outline->addItem(new OutlineItem(new TextString('Hybrid'), new Fit($page)));
        $catalog = new DocumentCatalog($pages, new Entry(new NameType('Version'), new NameType('1.5')), new Entry(new NameType('Outlines'), new IndirectReference($outline)));
        $parts = [];
        foreach ([$font, $widths, $descriptor, $resources, $pages, $page, $catalog, $outline] as $index => $value) {
            $parts[] = new ObjectStreamEntry(new ObjectNumber(100 + $index), $value);
        }
        $container = new ObjectStream(...[...$parts, new FlateDecode()]);
        return [new FileStructure(new FileHeader('1.4'), new FileBody($catalog, $container), new IndirectReference($catalog), null, CrossReferenceFormat::Hybrid), $font, $widths, $descriptor, $outline, $container];
    }
}
