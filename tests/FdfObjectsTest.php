<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\TextFieldFlags;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\NonTerminalField;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\TextFieldFlag;
use PDF\Document\Forms\Fdf\FdfExporter;
use PDF\Document\Forms\Fdf\FdfCatalog;
use PDF\Document\Forms\Fdf\FdfCrossReference;
use PDF\Document\Forms\Fdf\FdfField;
use PDF\Document\Forms\Fdf\FdfFormData;
use PDF\Document\Forms\Fdf\FdfWriter;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\TestCase;

final class FdfObjectsTest extends TestCase
{
    private function fieldName(string $name): PartialFieldName
    {
        return new PartialFieldName(new TextString($name));
    }

    private function resources(): DictionaryType
    {
        return new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('Helv'), new IndirectReference(new Type1Font(StandardFont::Helvetica))))));
    }

    private function appearance(): DefaultAppearance
    {
        return new DefaultAppearance(new SetTextFontAndSize(new NameType('Helv'), new NumericType(12)));
    }

    private function tree(): FieldTree
    {
        return new FieldTree($this->appearance(), $this->resources());
    }

    public function testNativeFdfWithoutCrossReferenceTable(): void
    {
        $field = new FdfField($this->fieldName('Name'), new TextString('Ada'));
        $group = new FdfField($this->fieldName('Person'), children: new ArrayType($field));
        $bytes = (new FdfWriter())->serialize(new FdfCatalog(new FdfFormData(new ArrayType($group), new TextString('form.pdf'))));
        self::assertStringStartsWith('%FDF-1.2', $bytes);
        self::assertStringContainsString('/T (Person)', $bytes);
        self::assertStringContainsString('/V (Ada)', $bytes);
        self::assertStringContainsString('/Root 1 0 R', $bytes);
        self::assertStringNotContainsString("\nxref\n", $bytes);
        self::assertStringNotContainsString('/Pages', $bytes);
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }

    public function testNativeFdfTraditionalCrossReferenceOffsets(): void
    {
        $field = new FdfField($this->fieldName('Name'), new TextString('Ada'));
        $catalog = new FdfCatalog(new FdfFormData(new ArrayType(new IndirectReference($field))), new NameType('1.7'));
        $bytes = (new FdfWriter(FdfCrossReference::Traditional))->serialize($catalog);
        preg_match('/startxref\n(\d+)\n/', $bytes, $matches);
        self::assertSame("xref\n", substr($bytes, (int) $matches[1], 5));
        preg_match_all('/(\d{10}) 00000 n /', $bytes, $entries);
        foreach ($entries[1] as $offset) {
            self::assertMatchesRegularExpression('/^\d+ 0 obj\n/', substr($bytes, (int) $offset));
        }
        self::assertStringContainsString('/Version /1.7', $bytes);
    }

    public function testFdfFlagReplacementOverridesSetAndClear(): void
    {
        $field = new FdfField($this->fieldName('x'), null, null, new Entry(new NameType('Ff'), new IntegerType(2)), new Entry(new NameType('SetFf'), new IntegerType(4)), new Entry(new NameType('ClrFf'), new IntegerType(2)));
        self::assertSame(2, $field->applyFieldFlags(new IntegerType(1))->value);
    }

    public function testFdfFlagsSetBeforeClear(): void
    {
        $field = new FdfField($this->fieldName('x'), null, null, new Entry(new NameType('SetFf'), new IntegerType(6)), new Entry(new NameType('ClrFf'), new IntegerType(2)));
        self::assertSame(5, $field->applyFieldFlags(new IntegerType(1))->value);
    }

    public function testFdfRejectsIncrementalTrailer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FdfWriter())->serialize(new FdfCatalog(new FdfFormData()), new DictionaryType(new Entry(new NameType('Prev'), new IntegerType(10))));
    }

    public function testFdfRejectsNonzeroGeneration(): void
    {
        $field = new FdfField($this->fieldName('x'), new TextString('data'));
        $catalog = new FdfCatalog(new FdfFormData(new ArrayType(new IndirectReference($field, new ObjectIdentifier(new ObjectNumber(7), new GenerationNumber(1))))));
        $this->expectException(\InvalidArgumentException::class);
        (new FdfWriter())->serialize($catalog);
    }

    public function testFdfExporterPreservesHierarchyAndExplicitSelection(): void
    {
        $name = new TextField($this->fieldName('Name'), value: new TextString('Ada'));
        $unused = new TextField($this->fieldName('NotSelected'), value: new TextString('private'));
        $tree = $this->tree()->addRoot((new NonTerminalField($this->fieldName('Person')))->addField($name)->addField($unused));
        $data = (new FdfExporter($tree))->export($name);
        $root = $data->get('Fields')->items[0];
        self::assertSame('Person', $root->get('T')->text);
        self::assertCount(1, $root->get('Kids'));
        self::assertSame('Ada', $root->get('Kids')->items[0]->get('V')->text);
    }

    public function testFdfExporterFileSelectValueIsAFileSpecificationWithoutFileAccess(): void
    {
        $field = new TextField($this->fieldName('file'), new TextFieldFlags(TextFieldFlag::FileSelect), new TextString('inexistant.txt'));
        $tree = $this->tree()->addRoot($field);
        $data = (new FdfExporter($tree))->export($field);
        $value = $data->get('Fields')->items[0]->get('V');
        self::assertSame('Filespec', $value->get('Type')->value);
        self::assertSame('inexistant.txt', $value->get('UF')->text);
    }

    public function testFdfPagesCannotBeCombinedWithFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FdfFormData(new ArrayType(), null, new Entry(new NameType('Pages'), new ArrayType())))->validate();
    }

    public function testFdfAnnotationsRejectWidgets(): void
    {
        $annotation = new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Widget')), new Entry(new NameType('Page'), new IntegerType(0)));
        $this->expectException(\InvalidArgumentException::class);
        (new FdfFormData(null, null, new Entry(new NameType('Annots'), new ArrayType($annotation))))->validate();
    }

    public function testFdfAnnotationsRequirePageOrdinal(): void
    {
        $annotation = new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Text')));
        $this->expectException(\InvalidArgumentException::class);
        (new FdfFormData(null, null, new Entry(new NameType('Annots'), new ArrayType($annotation))))->validate();
    }
}
