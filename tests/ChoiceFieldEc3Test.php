<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\ChoiceField;
use PDF\Document\Forms\ChoiceFieldFlags;
use PDF\Document\Forms\ChoiceFieldFlag;
use PDF\Document\Forms\ChoiceFieldValue;
use PDF\Document\Forms\ChoiceValueDomain;
use PDF\Document\Forms\ChoiceIndexSemantics;
use PDF\Document\Forms\ChoiceOption;
use PDF\Document\Forms\ChoiceOptions;
use PDF\Document\Forms\ChoiceSelection;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\FieldTree;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\TestCase;

final class ChoiceFieldEc3Test extends TestCase
{
    public function testSingleSelectionMayCarryIndicesInPdf20(): void
    {
        $field = $this->field(new ChoiceSelection(new IntegerType(1)));
        $field->validate();
        $field->validateVersion($this->version(PdfVersion::PDF20));
        self::assertCount(1, $field->get('I'));
    }

    public function testPdf17StillRequiresMultiSelectWhenIndicesArePresent(): void
    {
        $field = $this->field(new ChoiceSelection(new IntegerType(0)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires PDF 2.0');
        $field->validateVersion($this->version(PdfVersion::PDF17));
    }

    public function testPdf20RequiresIndicesForEqualExportsWithDifferentNames(): void
    {
        $field = $this->field(semantics: ChoiceIndexSemantics::Pdf17);
        $field->validate();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require explicit I indices');
        $field->validateVersion($this->version(PdfVersion::PDF20));
    }

    public function testPdf17DoesNotAcquireTheNewConditionalRequirement(): void
    {
        $field = $this->field(semantics: ChoiceIndexSemantics::Pdf17);
        $field->validate();
        $field->validateVersion($this->version(PdfVersion::PDF17));
        self::assertNull($field->get('I'));
    }

    public function testCatalogVersionSelectsThePdf20RuleOnAPdf17Header(): void
    {
        $field = $this->field(new ChoiceSelection(new IntegerType(1)));
        $version = new EffectivePdfVersion(new FileHeader(PdfVersion::PDF17), new DictionaryType(new Entry(new NameType('Version'), new NameType('2.0'))));
        $field->validateVersion($version);
        self::assertSame(PdfVersion::PDF20, $version->value);
    }

    public function testEc3DoesNotRequireIndicesMerelyBecauseTheValueIsAnArray(): void
    {
        $options = new ChoiceOptions(new ChoiceOption(new TextString('a')), new ChoiceOption(new TextString('b')));
        $field = new ChoiceField(null, $options, new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect), new ChoiceFieldValue(ChoiceValueDomain::ExportValues, new ArrayType(new TextString('a'), new TextString('b'))), indexSemantics: ChoiceIndexSemantics::Pdf20Ec3);
        $this->bind($field);
        $field->validate();
        $field->validateVersion($this->version(PdfVersion::PDF20));
        self::assertNull($field->get('I'));
    }

    private function ambiguousOptions(): ChoiceOptions
    {
        return new ChoiceOptions(new ChoiceOption(new TextString('First'), new TextString('same')), new ChoiceOption(new TextString('Second'), new TextString('same')));
    }

    private function field(?ChoiceSelection $selection = null, ChoiceIndexSemantics $semantics = ChoiceIndexSemantics::Pdf20Ec3): ChoiceField
    {
        $field = new ChoiceField(null, $this->ambiguousOptions(), selection: $selection, indexSemantics: $semantics);
        $this->bind($field);
        return $field;
    }

    private function bind(ChoiceField $field): void
    {
        $resources = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F'), new DictionaryType()))));
        (new FieldTree(new DefaultAppearance(new SetTextFontAndSize(new NameType('F'), new NumericType(12))), $resources))->addRoot($field);
    }

    private function version(PdfVersion $version): EffectivePdfVersion
    {
        return new EffectivePdfVersion(new FileHeader($version), new DictionaryType());
    }
}
