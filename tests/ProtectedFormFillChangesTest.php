<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\AppearanceState;
use PDF\Document\Forms\AppearanceStates;
use PDF\Document\Forms\ButtonField;
use PDF\Document\Forms\ChoiceField;
use PDF\Document\Forms\ChoiceFieldFlag;
use PDF\Document\Forms\ChoiceFieldFlags;
use PDF\Document\Forms\ChoiceOption;
use PDF\Document\Forms\ChoiceOptions;
use PDF\Document\Forms\Field;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\FieldTypeDeclaration;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\NonTerminalField;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\FileStructure;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Tests\Support\ProtectedRevisionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtectedFormFillChangesTest extends TestCase
{
    /** @return iterable<string, array{DocMdpPermissions, CrossReferenceFormat}> */
    public static function permitted(): iterable
    {
        foreach ([DocMdpPermissions::FormFillingTemplatesAndSigning, DocMdpPermissions::FormsTemplatesSigningAndAnnotations] as $permission) {
            foreach (CrossReferenceFormat::cases() as $format) {
                yield $permission->name . ':' . $format->name => [$permission, $format];
            }
        }
    }

    #[DataProvider('permitted')]
    public function testPlainTextFillPreservesCertifiedBytesAndHistoricalPolicy(DocMdpPermissions $permission, CrossReferenceFormat $format): void
    {
        $fixture = new ProtectedRevisionFixture($permission, format: $format);
        $filled = $fixture->mutation('field-value')->toRevision();
        self::assertStringStartsWith($fixture->revision->bytes, $filled->bytes);
        self::assertSame('changed', $filled->binding($fixture->text)->dictionary->get('V')->value);
        $again = $this->replace($filled, $fixture->text, ['V' => new TextString('second')])->toRevision();
        self::assertStringStartsWith($filled->bytes, $again->bytes);
        $this->expectException(\LogicException::class);
        $this->replace($again, $fixture->text, ['T' => new TextString('renamed')])->toRevision();
    }

    /** @return iterable<string, array{SignatureLockAction, list<string>, bool}> */
    public static function fieldLocks(): iterable
    {
        yield 'all' => [SignatureLockAction::All, [], false];
        yield 'include-this' => [SignatureLockAction::Include, ['name'], false];
        yield 'include-other' => [SignatureLockAction::Include, ['other'], true];
        yield 'exclude-this' => [SignatureLockAction::Exclude, ['name'], true];
        yield 'exclude-other' => [SignatureLockAction::Exclude, ['other'], false];
    }

    #[DataProvider('fieldLocks')]
    public function testFieldMdpAloneChecksActualFilledField(SignatureLockAction $action, array $names, bool $allowed): void
    {
        $lock = new SignatureFieldLock($action, $action === SignatureLockAction::All ? null : new ArrayType(...array_map(static fn (string $name) => new TextString($name), $names)));
        $fixture = new ProtectedRevisionFixture(null, $lock);
        if (!$allowed) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('FieldMDP');
        }
        $filled = $fixture->mutation('field-value')->toRevision();
        self::assertSame('changed', $filled->binding($fixture->text)->dictionary->get('V')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function hiddenChanges(): iterable
    {
        foreach (['content', 'page', 'orphan', 'remove-perms', 'remove-form', 'lang', 'field-name'] as $attack) {
            yield $attack => [$attack];
        }
    }

    #[DataProvider('hiddenChanges')]
    public function testFillDoesNotPermitHiddenChanges(string $attack): void
    {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormFillingTemplatesAndSigning);
        $fill = $fixture->mutation('field-value');
        $other = $fixture->mutation($attack);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($fixture->revision, new FileBody(...[...$fill->body->objects(), ...$other->body->objects()])))->toRevision();
    }

    public function testInheritedTypeAndValueCanBeOverriddenOnlyAtExistingTerminal(): void
    {
        $field = new TextField($this->fieldName('child'), declaration: FieldTypeDeclaration::Inherited);
        $parent = (new NonTerminalField($this->fieldName('group'), $this->entry('FT', new NameType('Tx')), $this->entry('V', new TextString('inherited'))))->addField($field);
        $revision = $this->certified($parent);
        $filled = $this->replace($revision, $field, ['V' => new TextString('local')])->toRevision();
        self::assertSame('local', $filled->binding($field)->dictionary->get('V')->value);
        self::assertSame('inherited', $filled->binding($parent)->dictionary->get('V')->value);
        $this->expectException(\LogicException::class);
        $this->replace($revision, $parent, ['V' => new TextString('all descendants')])->toRevision();
    }

    public function testInheritedReadOnlyCannotBeBypassedByAChildValue(): void
    {
        $field = new TextField($this->fieldName('child'));
        $parent = (new NonTerminalField($this->fieldName('group'), $this->entry('Ff', new IntegerType(1))))->addField($field);
        $revision = $this->certified($parent);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('read-only');
        $this->replace($revision, $field, ['V' => new TextString('changed')])->toRevision();
    }

    public function testFieldMdpNonterminalNameProtectsItsDescendants(): void
    {
        $field = new TextField($this->fieldName('child'));
        $parent = (new NonTerminalField($this->fieldName('group')))->addField($field);
        $lock = new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('group')));
        $revision = $this->certified($parent, lock: $lock);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FieldMDP');
        $this->replace($revision, $field, ['V' => new TextString('changed')])->toRevision();
    }

    public function testChoiceSelectionAndIndicesAreBoundToExistingOptions(): void
    {
        $field = new ChoiceField($this->fieldName('choice'), new ChoiceOptions(new ChoiceOption(new TextString('A')), new ChoiceOption(new TextString('B'))), new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect));
        $revision = $this->certified($field);
        $filled = $this->replace($revision, $field, ['V' => new ArrayType(new TextString('B')), 'I' => new ArrayType(new IntegerType(1)), 'TI' => new IntegerType(1)])->toRevision();
        self::assertSame(1, $filled->binding($field)->dictionary->get('I')->items[0]->integer());
        $this->expectException(\LogicException::class);
        $this->replace($revision, $field, ['V' => new ArrayType(new TextString('B')), 'I' => new ArrayType(new IntegerType(0))])->toRevision();
    }

    public function testChoiceCannotChangeOptionsWhileFilling(): void
    {
        $field = new ChoiceField($this->fieldName('choice'), new ChoiceOptions(new ChoiceOption(new TextString('A'))));
        $revision = $this->certified($field);
        $this->expectException(\LogicException::class);
        $this->replace($revision, $field, ['V' => new TextString('B'), 'Opt' => new ArrayType(new TextString('B'))])->toRevision();
    }

    /** @return iterable<string, array{bool}> */
    public static function widgetRepresentations(): iterable
    {
        yield 'separate' => [false];
        yield 'merged' => [true];
    }

    #[DataProvider('widgetRepresentations')]
    public function testCheckboxValueAndAppearanceStateChangeTogether(bool $merged): void
    {
        $field = new ButtonField($this->fieldName('check'), value: new NameType('Off'));
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance(new AppearanceStates(new AppearanceState(new NameType('Off'), $this->appearance()), new AppearanceState(new NameType('Yes'), $this->appearance()))), new NameType('Off'));
        $merged ? $field->mergeWidget($widget) : $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget, merged: $merged);
        $fieldRecord = $revision->binding($field);
        $fieldDictionary = $fieldRecord->dictionary->with($this->entry('V', new NameType('Yes')));
        $objects = [];
        if ($merged) {
            $fieldDictionary = $fieldDictionary->with($this->entry('AS', new NameType('Yes')));
        } else {
            $widgetRecord = $revision->binding($widget);
            $objects[] = new IndirectObject($widgetRecord->dictionary->with($this->entry('AS', new NameType('Yes'))), $widgetRecord->identifier);
        }
        $objects[] = new IndirectObject($fieldDictionary, $fieldRecord->identifier);
        $filled = (new IncrementalUpdate($revision, new FileBody(...$objects)))->toRevision();
        self::assertSame('Yes', $filled->binding($field)->dictionary->get('V')->value);
        self::assertSame('Yes', $filled->binding($merged ? $field : $widget)->dictionary->get('AS')->value);
        $this->expectException(\LogicException::class);
        $this->replace($revision, $field, ['V' => new NameType('Yes')])->toRevision();
    }

    public function testNewTextAppearanceCannotMutateHistoricalStreamInPlace(): void
    {
        $field = new TextField($this->fieldName('text'), value: new TextString('old'));
        $stream = $this->appearance();
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($stream));
        $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget);
        $record = $revision->binding($stream);
        $fieldRecord = $revision->binding($field);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($revision, new FileBody(
            new IndirectObject($fieldRecord->dictionary->with($this->entry('V', new TextString('new'))), $fieldRecord->identifier),
            new IndirectObject(new StreamObject("/Tx BMC\nq Q\nEMC\n", $record->dictionary), $record->identifier),
        )))->toRevision();
    }

    public function testTextValueAndNewAppearanceAreOneClassifiedFill(): void
    {
        $field = new TextField($this->fieldName('text'), value: new TextString('old'));
        $stream = $this->appearance();
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($stream));
        $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget);
        $fresh = new StreamObject("/Tx BMC\nq Q\nEMC\n", $revision->binding($stream)->dictionary->without('Length'));
        $fieldRecord = $revision->binding($field);
        $widgetRecord = $revision->binding($widget);
        $filled = (new IncrementalUpdate($revision, new FileBody(
            new IndirectObject($fieldRecord->dictionary->with($this->entry('V', new TextString('new'))), $fieldRecord->identifier),
            new IndirectObject($widgetRecord->dictionary->with($this->entry('AP', new DictionaryType($this->entry('N', new IndirectReference($fresh))))), $widgetRecord->identifier),
        )))->toRevision();
        self::assertStringStartsWith($revision->bytes, $filled->bytes);
        self::assertSame('new', $filled->binding($field)->dictionary->get('V')->value);
        self::assertSame($revision->binding($stream)->snapshot->encodedBytes, $filled->binding($stream)->snapshot->encodedBytes);
    }

    public function testAnAppearanceOnlyChangeStillHonoursFieldMdp(): void
    {
        $field = new TextField($this->fieldName('text'), value: new TextString('old'));
        $stream = $this->appearance();
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($stream));
        $field->addWidget($widget);
        $revision = $this->certified($field, new SignatureFieldLock(SignatureLockAction::All), $widget);
        $fresh = new StreamObject("/Tx BMC\nq Q\nEMC\n", $revision->binding($stream)->dictionary->without('Length'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FieldMDP');
        $this->replace($revision, $widget, ['AP' => new DictionaryType($this->entry('N', new IndirectReference($fresh)))])->toRevision();
    }

    public function testFillingDoesNotAddAnUncomparedRolloverAppearance(): void
    {
        $field = new TextField($this->fieldName('text'), value: new TextString('old'));
        $normal = $this->appearance();
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($normal));
        $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget);
        $fresh = new StreamObject("q 1 0 0 rg 0 0 200 200 re f Q\n", $revision->binding($normal)->dictionary->without('Length'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('appearance alternative without a historical program');
        $this->replace($revision, $widget, ['AP' => new DictionaryType(
            $this->entry('N', new IndirectReference($normal)),
            $this->entry('R', new IndirectReference($fresh)),
        )])->toRevision();
    }

    public function testFillingDoesNotSelectANewUncomparedAppearanceState(): void
    {
        $field = new TextField($this->fieldName('text'), value: new TextString('old'));
        $normal = $this->appearance();
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance(new AppearanceStates(new AppearanceState(new NameType('Original'), $normal))), new NameType('Original'));
        $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget);
        $fresh = new StreamObject("q 1 0 0 rg 0 0 200 200 re f Q\n", $revision->binding($normal)->dictionary->without('Length'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('appearance state without a historical program');
        $this->replace($revision, $widget, ['AS' => new NameType('Added'), 'AP' => new DictionaryType($this->entry('N', new DictionaryType(
            $this->entry('Original', new IndirectReference($normal)),
            $this->entry('Added', new IndirectReference($fresh)),
        )))])->toRevision();
    }

    public function testIndirectTextValueDoesNotGrantAnArbitraryNewObject(): void
    {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormFillingTemplatesAndSigning);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('direct plain text');
        $this->replace($fixture->revision, $fixture->text, ['V' => new IndirectReference(new TextString('indirect'))])->toRevision();
    }

    public function testAnExtraAliasFromTheSameFormOwnerDoesNotAuthorizeSharedFieldChanges(): void
    {
        $field = new TextField($this->fieldName('text'));
        $revision = $this->certified($field, sharedAlias: true);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unrelated shared reference');
        $this->replace($revision, $field, ['V' => new TextString('changed')])->toRevision();
    }

    public function testFillingCannotChangeAWidgetRectangle(): void
    {
        $field = new TextField($this->fieldName('text'));
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($this->appearance()));
        $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('geometry');
        $this->replace($revision, $widget, ['Rect' => new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(400), new IntegerType(400))])->toRevision();
    }

    public function testTextLimitsAndDefaultsCannotChangeWhileFilling(): void
    {
        $field = new TextField($this->fieldName('text'), maximumLength: new IntegerType(3));
        $revision = $this->certified($field);
        $filled = $this->replace($revision, $field, ['V' => new TextString('été')])->toRevision();
        self::assertNotNull($filled->binding($field)->dictionary->get('V'));
        $this->expectException(\LogicException::class);
        $this->replace($revision, $field, ['V' => new TextString('four')])->toRevision();
    }

    public function testFileSelectionFillingOnlyStoresTheExplicitTextAndDoesNotReadItsPath(): void
    {
        $field = new TextField($this->fieldName('file'), new \PDF\Document\Forms\TextFieldFlags(\PDF\Document\Forms\TextFieldFlag::FileSelect));
        $revision = $this->certified($field);
        $path = '/does-not-exist/private-document.pdf';
        $filled = $this->replace($revision, $field, ['V' => new TextString($path)])->toRevision();
        self::assertSame($path, $filled->binding($field)->dictionary->get('V')->value);
        self::assertStringStartsWith($revision->bytes, $filled->bytes);
    }

    public function testRichTextNeedsTheExplicitInterpreterOnEveryUpdate(): void
    {
        $field = $this->richField();
        $revision = $this->certified($field);
        $filled = $this->replace($revision, $field, [
            'V' => new TextString('Reviewed'), 'RV' => new TextString('<body><p><b>Reviewed</b></p></body>'),
        ], $this->richTextFilling())->toRevision();
        self::assertSame('Reviewed', $filled->binding($field)->dictionary->get('V')->value);
        self::assertStringStartsWith($revision->bytes, $filled->bytes);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit XML interpreter');
        $this->replace($filled, $field, ['V' => new TextString('next'), 'RV' => new TextString('<body><p>next</p></body>')])->toRevision();
    }

    public function testRichAndPlainValuesMustAgreeBeforeEmission(): void
    {
        $field = $this->richField();
        $revision = $this->certified($field);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('same text');
        $this->replace($revision, $field, ['V' => new TextString('approved'), 'RV' => new TextString('<body><p>rejected</p></body>')], $this->richTextFilling())->toRevision();
    }

    public function testRichTextRejectsExternalEntityDeclarations(): void
    {
        $field = $this->richField();
        $revision = $this->certified($field);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DTD');
        $this->replace($revision, $field, ['V' => new TextString('ignored'), 'RV' => new TextString('<!DOCTYPE body [<!ENTITY x SYSTEM "file:///private.txt">]><body><p>&x;</p></body>')], $this->richTextFilling())->toRevision();
    }

    public function testRichTextCannotLeaveAStaleWidgetAppearance(): void
    {
        $field = $this->richField();
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($this->appearance()));
        $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('regenerates the complete normal appearance');
        $this->replace($revision, $field, ['V' => new TextString('new'), 'RV' => new TextString('<body><p>new</p></body>')], $this->richTextFilling())->toRevision();
    }

    public function testRichTextReplacesTheWholeAppearanceWithoutThePlainTextTxConvention(): void
    {
        $field = $this->richField();
        $stream = $this->appearance();
        $widget = new WidgetAnnotation($this->box(), new WidgetAppearance($stream));
        $field->addWidget($widget);
        $revision = $this->certified($field, widget: $widget);
        $fresh = new StreamObject('q 0 0 1 rg 10 10 20 20 re f Q', $revision->binding($stream)->dictionary->without('Length'));
        $fieldRecord = $revision->binding($field);
        $widgetRecord = $revision->binding($widget);
        $filled = (new IncrementalUpdate($revision, new FileBody(
            new IndirectObject($fieldRecord->dictionary->with($this->entry('V', new TextString('new')))->with($this->entry('RV', new TextString('<body><p>new</p></body>'))), $fieldRecord->identifier),
            new IndirectObject($widgetRecord->dictionary->with($this->entry('AP', new DictionaryType($this->entry('N', new IndirectReference($fresh))))), $widgetRecord->identifier),
        ), null, null, $this->richTextFilling()))->toRevision();
        self::assertStringStartsWith($revision->bytes, $filled->bytes);
        self::assertSame('q 0 0 1 rg 10 10 20 20 re f Q', $filled->binding($fresh)->snapshot->encodedBytes);
        self::assertSame($revision->binding($stream)->snapshot->encodedBytes, $filled->binding($stream)->snapshot->encodedBytes);
    }

    public function testRichTextStillHonoursHistoricalFieldLocks(): void
    {
        $field = $this->richField();
        $revision = $this->certified($field, lock: new SignatureFieldLock(SignatureLockAction::All));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FieldMDP');
        $this->replace($revision, $field, ['V' => new TextString('new'), 'RV' => new TextString('<body><p>new</p></body>')], $this->richTextFilling())->toRevision();
    }

    private function richField(): TextField
    {
        return new TextField($this->fieldName('rich'), new \PDF\Document\Forms\TextFieldFlags(\PDF\Document\Forms\TextFieldFlag::RichText), new TextString('old'), entries: $this->entry('RV', new TextString('<body><p>old</p></body>')));
    }

    private function richTextFilling(): \PDF\Document\Signatures\RichTextFilling
    {
        return new \PDF\Document\Signatures\RichTextFilling(new \PDF\Document\Structure\WtPdfXmlRichTextParser());
    }

    /** @param array<string, BasicType> $entries */
    private function replace(FileRevision $revision, BasicType $object, array $entries, ?\PDF\Document\Signatures\RichTextFilling $richTextFilling = null): IncrementalUpdate
    {
        $record = $revision->binding($object);
        $dictionary = $record->dictionary;
        foreach ($entries as $name => $value) {
            $dictionary = $dictionary->with($this->entry($name, $value));
        }
        return new IncrementalUpdate($revision, new FileBody(new IndirectObject($dictionary, $record->identifier)), null, null, ...($richTextFilling === null ? [] : [$richTextFilling]));
    }

    private function certified(Field $field, ?SignatureFieldLock $lock = null, ?WidgetAnnotation $widget = null, bool $merged = false, bool $sharedAlias = false): FileRevision
    {
        $backend = (new ProtectedRevisionFixture())->backend;
        $author = new SignatureFieldSigning($backend, $this->fieldName('author'), new SignatureCapacity(8192), new SignatureSigningIntent(certification: DocMdpPermissions::FormFillingTemplatesAndSigning), lock: $lock);
        $font = require dirname(__DIR__) . '/examples/fixtures/pdf20-courier-font.php';
        $resources = new DictionaryType($this->entry('Font', new DictionaryType($this->entry('F', new IndirectReference($font)))));
        $appearance = new \PDF\Document\Forms\DefaultAppearance(new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F'), new IntegerType(12)));
        $form = new AcroForm((new FieldTree($appearance, $resources))->addRoot($author->field)->addRoot($field), null, ...($sharedAlias ? [$this->entry('PrivateAlias', new IndirectReference($field))] : []));
        $pageEntries = $widget === null ? [] : [$this->entry('Annots', new ArrayType(new IndirectReference($merged ? $field : $widget)))];
        $page = new PageObject($this->box(), new ResourceDictionary(), null, ...$pageEntries);
        $widget?->bindToPage($page);
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $this->entry('AcroForm', new IndirectReference($form)), $this->entry('Perms', $author->certificationPermissions()));
        $author->bindCatalog(new IndirectReference($catalog));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader('2.0'), new FileBody($catalog)), $author->signature);
        return $author->sign($prepared)->revision;
    }

    private function fieldName(string $value): PartialFieldName
    {
        return new PartialFieldName(new TextString($value));
    }

    private function entry(string $name, BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }

    private function box(): Rectangle
    {
        return new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200));
    }

    private function appearance(): FormAppearanceStream
    {
        return new FormAppearanceStream(new ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContent(new NameType('Tx')), new \PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent()), $this->box(), new DictionaryType());
    }
}
