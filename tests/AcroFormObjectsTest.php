<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\AppearanceState;
use PDF\Document\Forms\AppearanceStates;
use PDF\Document\Forms\BarcodeField;
use PDF\Document\Forms\ButtonField;
use PDF\Document\Forms\ButtonFieldFlag;
use PDF\Document\Forms\CalculationOrder;
use PDF\Document\Forms\ChoiceField;
use PDF\Document\Forms\ChoiceFieldFlag;
use PDF\Document\Forms\ChoiceOption;
use PDF\Document\Forms\ChoiceOptions;
use PDF\Document\Forms\ChoiceSelection;
use PDF\Document\Forms\ChoiceFieldValue;
use PDF\Document\Forms\ChoiceValueDomain;
use PDF\Document\Forms\FieldTextStream;
use PDF\Document\Forms\CommonFieldFlag;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\TextFieldFlags;
use PDF\Document\Forms\ChoiceFieldFlags;
use PDF\Document\Forms\ButtonFieldFlags;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\FieldTypeDeclaration;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\NonTerminalField;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\TerminalField;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\TextFieldFlag;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\Forms\XfaPacket;
use PDF\Document\Forms\XfaPackets;
use PDF\Document\Forms\IconFit;
use PDF\Document\Forms\IconScaling;
use PDF\Document\Forms\IconScaleWhen;
use PDF\Document\Forms\IconAlignment;
use PDF\Document\Forms\NamedPageTemplate;
use PDF\Document\Forms\BarcodePaperMetadata;
use PDF\Document\Forms\BarcodeSymbology;
use PDF\Document\Barcodes\BarcodeDataPreparation;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextMatrix;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\CatalogDictionary\AcroForm as AcroFormEntry;
use PDF\FileStructure;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\TestCase;

final class AcroFormObjectsTest extends TestCase
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

    private function box(): Rectangle
    {
        return new Rectangle(new NumericType(0), new NumericType(0), new NumericType(120), new NumericType(24));
    }

    private function stream(): FormAppearanceStream
    {
        return new FormAppearanceStream(new ContentStreams(), $this->box(), new DictionaryType());
    }

    private function widget(TerminalField $field, ?WidgetAppearance $appearance = null, ?NameType $state = null): WidgetAnnotation
    {
        $widget = new WidgetAnnotation($this->box(), $appearance, $state);
        $page = new PageObject($this->box(), new ResourceDictionary(), null, new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($widget))));
        (new PageTreeNode())->addKid($page);
        $widget->bindToPage($page);
        $field->addWidget($widget);
        return $widget;
    }

    private function buttonAppearance(string $on = 'Yes'): WidgetAppearance
    {
        return new WidgetAppearance(new AppearanceStates(new AppearanceState(new NameType('Off'), $this->stream()), new AppearanceState(new NameType($on), $this->stream())));
    }

    private function choiceValue(TextString|ArrayType $value): ChoiceFieldValue
    {
        return new ChoiceFieldValue(ChoiceValueDomain::ExportValues, $value);
    }

    public function testQualifiedNamesAndInheritedFieldTypeFlagsAppearanceAndQuadding(): void
    {
        $root = new NonTerminalField($this->fieldName('PersonalData'), new Entry(new NameType('FT'), new NameType('Tx')), new Entry(new NameType('Ff'), new IntegerType(1)), new Entry(new NameType('Q'), new IntegerType(2)));
        $address = new NonTerminalField($this->fieldName('Address'));
        $zip = new TextField($this->fieldName('ZipCode'), value: new TextString('75001'), declaration: FieldTypeDeclaration::Inherited);
        $address->addField($zip);
        $root->addField($address);
        $tree = $this->tree()->addRoot($root);
        (new AcroForm($tree))->validate();
        self::assertSame('PersonalData.Address.ZipCode', $zip->qualifiedName());
        self::assertSame(1, $zip->flags());
        self::assertSame('Tx', $zip->effective('FT')->value);
        self::assertSame(2, $zip->effective('Q')->value);
        self::assertSame($tree->defaultAppearance, $zip->effective('DA'));
        self::assertNull($zip->get('FT'));
        self::assertInstanceOf(IndirectReference::class, $zip->get('Parent'));
    }

    public function testPartialNamesRejectPeriods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fieldName('Personal.Address');
    }

    public function testTreeRejectsCycles(): void
    {
        $parent = new NonTerminalField($this->fieldName('A'));
        $child = new NonTerminalField($this->fieldName('B'));
        $parent->addField($child);
        $this->expectException(\InvalidArgumentException::class);
        $child->addField($parent);
    }

    public function testFieldCannotBelongToTwoParents(): void
    {
        $field = new TextField($this->fieldName('x'));
        (new NonTerminalField())->addField($field);
        $this->expectException(\InvalidArgumentException::class);
        (new NonTerminalField())->addField($field);
    }

    public function testFieldCannotBelongToTwoForms(): void
    {
        $field = new SignatureField($this->fieldName('signature'));
        (new FieldTree())->addRoot($field);
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTree())->addRoot($field);
    }

    public function testRepresentationsWithoutPartialNamesShareValues(): void
    {
        $first = new TextField(value: new TextString('same'));
        $second = new TextField(value: new TextString('same'));
        $root = (new NonTerminalField($this->fieldName('shared')))->addField($first)->addField($second);
        $this->tree()->addRoot($root)->validate();
        self::assertSame('shared', $first->qualifiedName());
        self::assertSame('shared', $second->qualifiedName());
    }

    public function testRepresentationsWithDifferentValuesAreRejected(): void
    {
        $root = (new NonTerminalField($this->fieldName('shared')))->addField(new TextField(value: new TextString('one')))->addField(new TextField(value: new TextString('two')));
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot($root)->validate();
    }

    public function testMissingInheritedTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot(new TextField($this->fieldName('x'), declaration: FieldTypeDeclaration::Inherited))->validate();
    }

    public function testTextMaximumCountsUnicodeCharacters(): void
    {
        $text = new TextField($this->fieldName('unicode'), value: new TextString('é😀'), maximumLength: new IntegerType(2));
        $this->tree()->addRoot($text)->validate();
        self::assertSame('é😀', $text->get('V')->text);
    }

    public function testTextMaximumRejectsExcessCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot(new TextField($this->fieldName('x'), value: new TextString('é😀X'), maximumLength: new IntegerType(2)))->validate();
    }

    public function testCombRequiresMaximumLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot(new TextField($this->fieldName('x'), new TextFieldFlags(TextFieldFlag::Comb)))->validate();
    }

    public function testCombCannotBeMultiline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot(new TextField($this->fieldName('x'), new TextFieldFlags(TextFieldFlag::Comb, TextFieldFlag::Multiline), maximumLength: new IntegerType(5)))->validate();
    }

    public function testCombAndCommonFlagsArePreserved(): void
    {
        $text = new TextField($this->fieldName('x'), new TextFieldFlags(TextFieldFlag::Comb, CommonFieldFlag::Required), maximumLength: new IntegerType(5));
        $this->tree()->addRoot($text)->validate();
        self::assertSame(16777218, $text->flags());
    }

    public function testRichTextValueRequiresExplicitRichXml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot(new TextField($this->fieldName('x'), new TextFieldFlags(TextFieldFlag::RichText), new TextString('text')))->validate();
    }

    public function testTextRejectsFlagsFromAnotherFieldType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot(new TextField($this->fieldName('x'), entries: new Entry(new NameType('Ff'), new IntegerType(ChoiceFieldFlag::Combo->value))))->validate();
    }

    public function testChoiceExportValuesAndIndicesStayExplicit(): void
    {
        $options = new ChoiceOptions(new ChoiceOption(new TextString('France'), new TextString('FR')), new ChoiceOption(new TextString('Belgique'), new TextString('BE')));
        $choice = new ChoiceField($this->fieldName('countries'), $options, new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect), $this->choiceValue(new ArrayType(new TextString('FR'), new TextString('BE'))), new ChoiceSelection(new IntegerType(0), new IntegerType(1)));
        $this->tree()->addRoot($choice)->validate();
        self::assertSame('FR', $choice->get('Opt')->items[0]->items[0]->text);
        self::assertSame('Belgique', $choice->get('Opt')->items[1]->items[1]->text);
        self::assertSame(1, $choice->get('I')->items[1]->value);
    }

    public function testArrayChoiceRequiresIndices(): void
    {
        $choice = new ChoiceField($this->fieldName('x'), new ChoiceOptions(new ChoiceOption(new TextString('one'))), new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect), $this->choiceValue(new ArrayType(new TextString('one'))));
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot($choice)->validate();
    }

    public function testChoiceIndicesAreAscending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChoiceSelection(new IntegerType(2), new IntegerType(1));
    }

    public function testChoiceIndicesMustBeWithinOptions(): void
    {
        $choice = new ChoiceField($this->fieldName('x'), new ChoiceOptions(new ChoiceOption(new TextString('one'))), new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect), $this->choiceValue(new TextString('one')), new ChoiceSelection(new IntegerType(5)));
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot($choice)->validate();
    }

    public function testEditableComboCanContainAValueAbsentFromOptions(): void
    {
        $choice = new ChoiceField($this->fieldName('x'), new ChoiceOptions(), new ChoiceFieldFlags(ChoiceFieldFlag::Combo, ChoiceFieldFlag::Edit), $this->choiceValue(new TextString('custom')));
        $this->tree()->addRoot($choice)->validate();
        self::assertSame('custom', $choice->get('V')->text);
    }

    public function testEditRequiresCombo(): void
    {
        $choice = new ChoiceField($this->fieldName('x'), new ChoiceOptions(), new ChoiceFieldFlags(ChoiceFieldFlag::Edit));
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot($choice)->validate();
    }

    public function testChoiceValueRemainsAuthoritativeWhenIndicesDiffer(): void
    {
        $choice = new ChoiceField($this->fieldName('x'), new ChoiceOptions(new ChoiceOption(new TextString('one')), new ChoiceOption(new TextString('two'))), new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect), $this->choiceValue(new ArrayType(new TextString('one'))), new ChoiceSelection(new IntegerType(1)));
        $this->tree()->addRoot($choice)->validate();
        self::assertSame('one', $choice->get('V')->items[0]->text);
    }

    public function testDefaultAppearanceNeedsTfAndResourceResolution(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DefaultAppearance();
    }

    public function testDefaultAppearanceRejectsTextObjectDelimiters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DefaultAppearance(new BeginTextObject(), new SetTextFontAndSize(new NameType('Helv'), new NumericType(12)));
    }

    public function testDefaultAppearanceRejectsTwoTextMatrices(): void
    {
        $matrix = new Matrix(new NumericType(1), new NumericType(0), new NumericType(0), new NumericType(1), new NumericType(0), new NumericType(0));
        $this->expectException(\InvalidArgumentException::class);
        new DefaultAppearance(new SetTextFontAndSize(new NameType('Helv'), new NumericType(12)), new SetTextMatrix($matrix), new SetTextMatrix($matrix));
    }

    public function testMissingDefaultFontResourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DefaultAppearance(new SetTextFontAndSize(new NameType('Missing'), new NumericType(0))))->validateResources($this->resources());
    }

    public function testWidgetParentPageAndAppearanceReferences(): void
    {
        $field = new TextField($this->fieldName('x'));
        $widget = $this->widget($field, new WidgetAppearance($this->stream()));
        $form = new AcroForm($this->tree()->addRoot($field));
        $form->validate();
        self::assertSame($field, $widget->get('Parent')->target());
        self::assertSame('Widget', $widget->get('Subtype')->value);
        self::assertInstanceOf(IndirectReference::class, $widget->get('AP')->get('N'));
        self::assertSame('Form', $widget->get('AP')->get('N')->target()->dictionary()->get('Subtype')->value);
    }

    public function testWidgetCannotHaveTwoFields(): void
    {
        $first = new SignatureField($this->fieldName('x'));
        $widget = $this->widget($first, new WidgetAppearance($this->stream()));
        $this->expectException(\InvalidArgumentException::class);
        (new SignatureField($this->fieldName('y')))->addWidget($widget);
    }

    public function testWidgetRequiresItsPageAnnotsMembership(): void
    {
        $field = new SignatureField($this->fieldName('x'));
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($this->stream()));
        $field->addWidget($widget);
        $page = new PageObject($this->box(), new ResourceDictionary());
        (new PageTreeNode())->addKid($page);
        $widget->bindToPage($page);
        $this->expectException(\InvalidArgumentException::class);
        (new AcroForm((new FieldTree())->addRoot($field)))->validate();
    }

    public function testMissingAppearanceRequiresExplicitNeedAppearances(): void
    {
        $field = new TextField($this->fieldName('x'));
        $this->widget($field);
        $this->expectException(\InvalidArgumentException::class);
        (new AcroForm($this->tree()->addRoot($field)))->validate();
    }

    public function testExplicitNeedAppearancesAllowsViewerConstructedAppearances(): void
    {
        $field = new TextField($this->fieldName('x'));
        $this->widget($field);
        $tree = (new FieldTree($this->appearance(), $this->resources(), needAppearances: new BooleanType(true)))->addRoot($field);
        (new AcroForm($tree))->validate();
        self::assertTrue($tree->needAppearances->value);
    }

    public function testSelectedCheckBoxRequiresMatchingState(): void
    {
        $field = new ButtonField($this->fieldName('check'), value: new NameType('Yes'));
        $this->widget($field, $this->buttonAppearance(), new NameType('Off'));
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTree())->addRoot($field)->validate();
    }

    public function testCheckBoxAndRadioValuesAndAppearancesAreValidated(): void
    {
        $check = new ButtonField($this->fieldName('check'), value: new NameType('Yes'));
        $this->widget($check, $this->buttonAppearance(), new NameType('Yes'));
        $radio = new ButtonField($this->fieldName('radio'), new ButtonFieldFlags(ButtonFieldFlag::Radio, ButtonFieldFlag::NoToggleToOff), new NameType('A'));
        $this->widget($radio, $this->buttonAppearance('A'), new NameType('A'));
        $this->widget($radio, $this->buttonAppearance('B'), new NameType('Off'));
        (new AcroForm((new FieldTree())->addRoot($check)->addRoot($radio)))->validate();
        self::assertSame('Yes', $check->get('V')->value);
        self::assertSame('A', $radio->get('V')->value);
    }

    public function testMutuallyExclusiveRadioWidgetsCannotBothBeOn(): void
    {
        $field = new ButtonField($this->fieldName('radio'), new ButtonFieldFlags(ButtonFieldFlag::Radio), new NameType('A'));
        $this->widget($field, $this->buttonAppearance('A'), new NameType('A'));
        $this->widget($field, $this->buttonAppearance('A'), new NameType('A'));
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTree())->addRoot($field)->validate();
    }

    public function testRadiosInUnisonSelectsDuplicateOnStatesTogether(): void
    {
        $field = new ButtonField($this->fieldName('radio'), new ButtonFieldFlags(ButtonFieldFlag::Radio, ButtonFieldFlag::RadiosInUnison), new NameType('A'));
        $this->widget($field, $this->buttonAppearance('A'), new NameType('A'));
        $this->widget($field, $this->buttonAppearance('A'), new NameType('A'));
        (new FieldTree())->addRoot($field)->validate();
        self::assertCount(2, $field->widgets());
    }

    public function testPushbuttonCannotRetainAValue(): void
    {
        $field = new ButtonField($this->fieldName('push'), new ButtonFieldFlags(ButtonFieldFlag::Pushbutton), new NameType('Yes'));
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTree())->addRoot($field)->validate();
    }

    public function testStateMustExistInAppearanceDictionary(): void
    {
        $field = new ButtonField($this->fieldName('check'), value: new NameType('Yes'));
        $this->widget($field, $this->buttonAppearance(), new NameType('Missing'));
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTree())->addRoot($field)->validate();
    }

    public function testCalculationOrderRequiresThisFormsCalculationFields(): void
    {
        $calculation = new Entry(new NameType('AA'), new DictionaryType(new Entry(new NameType('C'), new DictionaryType(new Entry(new NameType('S'), new NameType('JavaScript'))))));
        $field = new TextField($this->fieldName('sum'), entries: $calculation);
        $form = new AcroForm($this->tree()->addRoot($field), new CalculationOrder($field));
        $form->validate();
        self::assertSame($field, $form->get('CO')->items[0]->target());
    }

    public function testCalculationActionRequiresOrderMembership(): void
    {
        $field = new TextField($this->fieldName('sum'), entries: new Entry(new NameType('AA'), new DictionaryType(new Entry(new NameType('C'), new DictionaryType()))));
        $this->expectException(\InvalidArgumentException::class);
        (new AcroForm($this->tree()->addRoot($field)))->validate();
    }

    public function testSignatureLockPermissionsOnlyRestrictExistingPermissions(): void
    {
        $lock = new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('PersonalData.Name')), new IntegerType(3));
        $signature = new SignatureField($this->fieldName('signature'), lock: $lock);
        (new AcroForm((new FieldTree())->addRoot($signature)))->validate();
        self::assertSame(2, $lock->effectivePermissions(new IntegerType(2))->value);
        self::assertSame($lock, $signature->get('Lock')->target());
        self::assertSame('adobe_supplement_iso32000.pdf', $lock->definition()->source->document);
    }

    public function testSignatureLockIncludeRequiresFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureFieldLock(SignatureLockAction::Include);
    }

    public function testBarcodeDeclaresPreparationButRequiresWidgetPaperMetadata(): void
    {
        $barcode = new BarcodeField($this->fieldName('code'), new BarcodeDataPreparation(new IntegerType(1)), new TextString('message'));
        $this->tree()->addRoot($barcode)->validate();
        self::assertSame(1, $barcode->get('DataPrep')->value);
        self::assertSame('adobe_supplement_iso32000.pdf', $barcode->definition()->source->document);
    }

    public function testXfaPacketsKeepNamesAndReferencesInOrder(): void
    {
        $packets = new XfaPackets(new XfaPacket(new TextString('template'), new StreamObject('<template/>')), new XfaPacket(new TextString('datasets'), new StreamObject('<datasets/>')));
        self::assertSame('template', $packets->items[0]->text);
        self::assertInstanceOf(IndirectReference::class, $packets->items[1]);
        self::assertSame('datasets', $packets->items[2]->text);
    }







    public function testGenericMutationCannotBreakFieldOwnership(): void
    {
        $this->expectException(\LogicException::class);
        (new TextField($this->fieldName('x')))->with(new Entry(new NameType('Kids'), new ArrayType()));
    }






    public function testIconProportionalScalingAndAlignmentComputeTheMatrix(): void
    {
        $icon = new Rectangle(new NumericType(10), new NumericType(20), new NumericType(110), new NumericType(70));
        $viewport = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(200));
        $matrix = (new IconFit())->transform($icon, $viewport);
        self::assertSame(2, $matrix->a->value);
        self::assertSame(2, $matrix->d->value);
        self::assertEquals(-20, $matrix->e->value);
        self::assertEquals(10, $matrix->f->value);
    }

    public function testIconAnamorphicScalingUsesBothDimensions(): void
    {
        $viewport = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(240), new NumericType(96));
        $matrix = (new IconFit(scaling: IconScaling::Anamorphic))->transform($this->box(), $viewport);
        self::assertSame(2, $matrix->a->value);
        self::assertSame(4, $matrix->d->value);
        self::assertEquals(0, $matrix->e->value);
        self::assertEquals(0, $matrix->f->value);
    }

    public function testIconNeverScalingPreservesDimensionsAndUsesAlignment(): void
    {
        $viewport = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(240), new NumericType(96));
        $matrix = (new IconFit(IconScaleWhen::Never, alignment: new IconAlignment(new NumericType(1), new NumericType(0))))->transform($this->box(), $viewport);
        self::assertSame(1, $matrix->a->value);
        self::assertEquals(120, $matrix->e->value);
        self::assertEquals(0, $matrix->f->value);
    }

    public function testInvisibleNamedTemplatesHaveNeitherParentNorB(): void
    {
        $template = new NamedPageTemplate($this->box(), $this->resources(), new StreamObject(new ContentStreams()));
        self::assertSame('Template', $template->get('Type')->value);
        self::assertFalse($template->has('Parent'));
        self::assertFalse($template->has('B'));
        self::assertInstanceOf(IndirectReference::class, $template->get('Contents'));
    }

    public function testQrCodeMetadataRequiresEccInRange(): void
    {
        $metadata = new BarcodePaperMetadata(BarcodeSymbology::QrCode, new NumericType(1), new NumericType(1), new IntegerType(4), errorCorrection: new IntegerType(3));
        $metadata->validate();
        self::assertSame('QRCode', $metadata->get('Symbology')->value);
        self::assertFalse($metadata->has('XSymHeight'));
    }

    public function testPdf417MetadataRequiresIntegralModuleRatio(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BarcodePaperMetadata(BarcodeSymbology::Pdf417, new NumericType(1), new NumericType(1), new IntegerType(4), new IntegerType(5), new IntegerType(2));
    }

    public function testQrCodeMetadataRejectsExcessEcc(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BarcodePaperMetadata(BarcodeSymbology::QrCode, new NumericType(1), new NumericType(1), new IntegerType(4), errorCorrection: new IntegerType(4));
    }

    public function testXfaArrayRejectsMissingXmlStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AcroForm(new FieldTree(), entries: new Entry(new NameType('XFA'), new ArrayType(new TextString('template')))))->validate();
    }

    public function testTextStreamValuePreservesUnicodeAndMaximumLength(): void
    {
        $stream = new FieldTextStream(new TextString('é😀'));
        $field = new TextField($this->fieldName('stream'), value: $stream, maximumLength: new IntegerType(2));
        $this->tree()->addRoot($field)->validate();
        self::assertSame($stream, $field->get('V')->target());
        self::assertSame("\xfe\xff", substr($stream->data, 0, 2));
    }

    public function testChoiceDisplayNameInterpretationIsExplicit(): void
    {
        $choice = new ChoiceField($this->fieldName('country'), new ChoiceOptions(new ChoiceOption(new TextString('France'), new TextString('FR'))), value: new ChoiceFieldValue(ChoiceValueDomain::DisplayNames, new TextString('France')));
        $this->tree()->addRoot($choice)->validate();
        self::assertSame('France', $choice->get('V')->text);
        self::assertSame(ChoiceValueDomain::DisplayNames, $choice->value->domain);
    }

    public function testChoiceInheritedValueRequiresItsExplicitInterpretation(): void
    {
        $choice = new ChoiceField($this->fieldName('country'), new ChoiceOptions(new ChoiceOption(new TextString('France'), new TextString('FR'))), inheritedValueDomain: ChoiceValueDomain::ExportValues);
        $root = (new NonTerminalField($this->fieldName('Personal'), new Entry(new NameType('V'), new TextString('FR'))))->addField($choice);
        $this->tree()->addRoot($root)->validate();
        self::assertSame('FR', $choice->effective('V')->text);
        self::assertFalse($choice->has('V'));
    }

    public function testDuplicateChoiceExportsRequireIndicesEvenBeforeSelection(): void
    {
        $choice = new ChoiceField($this->fieldName('country'), new ChoiceOptions(new ChoiceOption(new TextString('One'), new TextString('same')), new ChoiceOption(new TextString('Two'), new TextString('same'))), new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect));
        $this->expectException(\InvalidArgumentException::class);
        $this->tree()->addRoot($choice)->validate();
    }

    public function testButtonExportOptionsUseNumericalAppearanceNames(): void
    {
        $field = new ButtonField($this->fieldName('check'), null, new NameType('0'), FieldTypeDeclaration::Explicit, new Entry(new NameType('Opt'), new ArrayType(new TextString('✓'))));
        $this->widget($field, $this->buttonAppearance('0'), new NameType('0'));
        (new FieldTree())->addRoot($field)->validate();
        self::assertSame('✓', $field->get('Opt')->items[0]->text);
    }

    public function testButtonExportOptionsRejectNonNumericalAppearanceNames(): void
    {
        $field = new ButtonField($this->fieldName('check'), null, new NameType('Yes'), FieldTypeDeclaration::Explicit, new Entry(new NameType('Opt'), new ArrayType(new TextString('✓'))));
        $this->widget($field, $this->buttonAppearance('Yes'), new NameType('Yes'));
        $this->expectException(\InvalidArgumentException::class);
        (new FieldTree())->addRoot($field)->validate();
    }

    public function testFormRejectsWidgetsInAnotherCatalogPageTree(): void
    {
        $field = new TextField($this->fieldName('x'));
        $widget = $this->widget($field, new WidgetAppearance($this->stream()));
        $form = new AcroForm($this->tree()->addRoot($field));
        $unrelated = (new PageTreeNode())->addKid(new PageObject($this->box(), new ResourceDictionary()));
        $this->expectException(\InvalidArgumentException::class);
        $form->validateCatalog(new DocumentCatalog($unrelated));
    }

    public function testFormValidatesTheActualCatalogPageTree(): void
    {
        $field = new TextField($this->fieldName('x'));
        $widget = $this->widget($field, new WidgetAppearance($this->stream()));
        $form = new AcroForm($this->tree()->addRoot($field));
        $form->validateCatalog(new DocumentCatalog($widget->page()->parent()));
        self::assertSame($widget->page(), $widget->get('P')->target());
    }

    public function testPdf417PaperMetadataAllowsRatiosOneThroughFour(): void
    {
        $metadata = new BarcodePaperMetadata(BarcodeSymbology::Pdf417, new NumericType(1), new NumericType(1), new IntegerType(4), new IntegerType(16), new IntegerType(8));
        $metadata->validate();
        self::assertSame(16, $metadata->get('XSymHeight')->value);
    }

    public function testMergedFieldAndWidgetHaveOneDictionaryWithoutKids(): void
    {
        $field = new TextField($this->fieldName('merged'), value: new TextString('Ada'));
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($this->stream()));
        $field->mergeWidget($widget);
        $page = new PageObject($this->box(), new ResourceDictionary(), null, new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($field))));
        $pages = (new PageTreeNode())->addKid($page);
        $widget->bindToPage($page);
        $form = new AcroForm($this->tree()->addRoot($field));
        $form->validateCatalog(new DocumentCatalog($pages));
        self::assertSame('Widget', $field->get('Subtype')->value);
        self::assertSame('Tx', $field->get('FT')->value);
        self::assertFalse($field->has('Kids'));
        self::assertFalse($field->has('Parent'));
        self::assertSame($page, $field->get('P')->target());
    }

    public function testMergedFieldCannotAddAnAdditionalWidget(): void
    {
        $field = new TextField($this->fieldName('merged'));
        $field->mergeWidget(new WidgetAnnotation($this->box(), new WidgetAppearance($this->stream())));
        $this->expectException(\LogicException::class);
        $field->addWidget(new WidgetAnnotation($this->box(), new WidgetAppearance($this->stream())));
    }

    public function testActualFileWriterRejectsFormWidgetsInForeignPages(): void
    {
        $field = new TextField($this->fieldName('x'));
        $this->widget($field, new WidgetAppearance($this->stream()));
        $form = new AcroForm($this->tree()->addRoot($field));
        $unrelated = (new PageTreeNode())->addKid(new PageObject($this->box(), new ResourceDictionary()));
        $catalog = new DocumentCatalog($unrelated, new AcroFormEntry(new IndirectReference($form)));
        $file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
        $this->expectException(\InvalidArgumentException::class);
        $file->toPdf();
    }

    public function testAdobeSignatureLockPermissionsRequireCatalogExtensionDeclaration(): void
    {
        $field = new SignatureField($this->fieldName('signature'), lock: new SignatureFieldLock(SignatureLockAction::All, permissions: new IntegerType(2)));
        $form = new AcroForm((new FieldTree())->addRoot($field));
        $pages = (new PageTreeNode())->addKid(new PageObject($this->box(), new ResourceDictionary()));
        $this->expectException(\InvalidArgumentException::class);
        $form->validateCatalog(new DocumentCatalog($pages));
    }
}
