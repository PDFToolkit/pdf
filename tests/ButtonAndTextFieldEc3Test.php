<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Forms as Form;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Tests\Support\RadioOffFixture;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\DataStructure\TextString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ButtonAndTextFieldEc3Test extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testPdf20RadioWithoutOffCanChangeSelectionAndClearIt(CrossReferenceFormat $format): void
    {
        $fixture = new RadioOffFixture(format: $format);
        $changed = $fixture->select('B');
        $cleared = $fixture->select('Off', $changed);
        self::assertStringStartsWith($fixture->revision->bytes, $changed->bytes);
        self::assertStringStartsWith($changed->bytes, $cleared->bytes);
        self::assertSame('B', $changed->binding($fixture->field)->dictionary->get('V')->value);
        foreach ($fixture->widgets as $widget) {
            $record = $cleared->binding($widget);
            self::assertSame('Off', $record->dictionary->get('AS')->value);
            self::assertFalse($record->dictionary->get('AP')->get('N')->has('Off'));
        }
    }

    public function testOptionalOffDoesNotOverrideDocMdpP1(): void
    {
        $fixture = new RadioOffFixture(DocMdpPermissions::NoChanges);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('P1');
        $fixture->select('B');
    }

    public function testUndeclaredRadioOnValueStillCannotBeFilled(): void
    {
        $fixture = new RadioOffFixture();
        $this->expectException(\LogicException::class);
        $fixture->select('Unknown');
    }

    public function testPdf20ButtonSemanticsRequireTheActualEffectiveVersion(): void
    {
        $field = new Form\ButtonField(semantics: Form\ButtonFieldSemantics::Pdf20);
        $field->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType(new Entry(new NameType('Version'), new NameType('2.0')))));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires PDF 2.0');
        $field->validateVersion(new EffectivePdfVersion(new FileHeader('1.7'), new DictionaryType()));
    }

    public function testPdf20PushbuttonMayRetainValuesWithoutChangingItsBehaviour(): void
    {
        $field = new Form\ButtonField(flags: new Form\ButtonFieldFlags(Form\ButtonFieldFlag::Pushbutton), value: new NameType('declared'), semantics: Form\ButtonFieldSemantics::Pdf20, entries: new Entry(new NameType('DV'), new TextString('default')));
        $field->validate();
        $field->validateVersion(new EffectivePdfVersion(new FileHeader('2.0'), new DictionaryType()));
        self::assertSame('declared', $field->effective('V')->value);
        self::assertSame('default', $field->effective('DV')->text);
    }

    public function testHistoricalPushbuttonStillRejectsValues(): void
    {
        $field = new Form\ButtonField(flags: new Form\ButtonFieldFlags(Form\ButtonFieldFlag::Pushbutton), value: new NameType('declared'));
        $this->expectException(\InvalidArgumentException::class);
        $field->validate();
    }

    public function testNullTextValueIsPreservedAndResolvedAsAbsent(): void
    {
        $field = new Form\TextField(value: new NullType(), maximumLength: new IntegerType(0));
        $this->tree()->addRoot($field)->validate();
        self::assertInstanceOf(NullType::class, $field->get('V'));
        self::assertNull($field->effective('V'));
    }

    public function testNullEntryDoesNotMaskAnInheritedValue(): void
    {
        $field = new Form\TextField(value: new NullType());
        $parent = new Form\NonTerminalField(new Form\PartialFieldName(new TextString('parent')), new Entry(new NameType('V'), new TextString('inherited')));
        $parent->addField($field);
        $this->tree()->addRoot($parent)->validate();
        self::assertSame('inherited', $field->effective('V')->text);
    }

    private function tree(): Form\FieldTree
    {
        return new Form\FieldTree(new Form\DefaultAppearance(new \PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize(new NameType('F'), new IntegerType(12))), new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F'), new DictionaryType())))));
    }
}
