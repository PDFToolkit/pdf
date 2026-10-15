<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Retained ISO1 pp390–391/400/413–414 and ISO2 pp482–483/494–495/510–511. */
final class AnnotationRelationshipsTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function box(): Rectangle
    {
        return new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100));
    }

    private function annotation(string $kind = 'Text', Entry ...$entries): DictionaryType
    {
        return new DictionaryType($this->entry('Subtype', new NameType($kind)), $this->entry('Rect', $this->box()), ...$entries);
    }

    private function page(BasicType ...$annotations): PageObject
    {
        return new PageObject($this->box(), new ResourceDictionary(), null, $this->entry('Annots', new ArrayType(...$annotations)));
    }

    private function file(string $version, PageObject ...$pages): FileStructure
    {
        $tree = new PageTreeNode();
        foreach ($pages as $page) {
            $tree->addKid($page);
        }
        return new FileStructure(new FileHeader($version), new FileBody(new DocumentCatalog($tree)));
    }

    /** @return iterable<string,array{string}> */
    public static function editions(): iterable
    {
        yield 'ISO1' => ['1.7'];
        yield 'ISO2' => ['2.0'];
    }

    #[DataProvider('editions')]
    public function testOneIndirectAnnotationCannotBelongToTwoPages(string $version): void
    {
        $annotation = $this->annotation();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only one page');
        $this->file($version, $this->page(new IndirectReference($annotation)), $this->page(new IndirectReference($annotation)))->toRevision();
    }

    #[DataProvider('editions')]
    public function testNamesAreComparedAsDecodedTextWithinTheirPage(string $version): void
    {
        $one = $this->annotation('Text', $this->entry('NM', new TextString('name')));
        $two = $this->annotation('Text', $this->entry('NM', new HexadecimalString("\xFE\xFF\x00n\x00a\x00m\x00e")));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('distinct NM');
        $this->file($version, $this->page(new IndirectReference($one), new IndirectReference($two)))->toRevision();
    }

    #[DataProvider('editions')]
    public function testNamesMayRepeatAcrossPagesAndReferencesMayRepeatOnTheSamePage(string $version): void
    {
        $one = $this->annotation('Text', $this->entry('NM', new TextString('name')));
        $two = $this->annotation('Text', $this->entry('NM', new TextString('name')));
        $revision = $this->file($version, $this->page(new IndirectReference($one), new IndirectReference($one)), $this->page(new IndirectReference($two)))->toRevision();
        self::assertNotSame($revision->binding($one)->identifier, $revision->binding($two)->identifier);
    }

    public function testDirectCopiesHaveSeparateIdentitiesAndSubsidiaryObjectsMayBeShared(): void
    {
        $shared = new DictionaryType();
        $one = $this->annotation('Text', $this->entry('Private', new IndirectReference($shared)));
        $revision = $this->file('1.7', $this->page($one), $this->page($one))->toRevision();
        self::assertSame(2, substr_count($revision->bytes, '/Private ' . $revision->binding($shared)->identifier . ' R'));
    }

    #[DataProvider('editions')]
    public function testReplyMustReferToAnAnnotationOnItsOwnPage(string $version): void
    {
        $target = $this->annotation();
        $reply = $this->annotation('Text', $this->entry('IRT', new IndirectReference($target)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same effective page');
        $this->file($version, $this->page(new IndirectReference($target)), $this->page(new IndirectReference($reply)))->toRevision();
    }

    public function testReplyAndGroupHaveDifferentPrimaryRules(): void
    {
        $primary = $this->annotation();
        $reply = $this->annotation('Text', $this->entry('IRT', new IndirectReference($primary)));
        $member = $this->annotation('Text', $this->entry('IRT', new IndirectReference($primary)), $this->entry('RT', new NameType('Group')), $this->entry('Popup', new NameType('IgnoredGroupAttribute')));
        self::assertStringContainsString('/RT /Group', $this->file('1.7', $this->page(new IndirectReference($primary), new IndirectReference($reply), new IndirectReference($member)))->toPdf());
        $invalid = $this->annotation('Text', $this->entry('IRT', new IndirectReference($reply)), $this->entry('RT', new NameType('Group')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('primary annotation');
        $this->file('1.7', $this->page(new IndirectReference($primary), new IndirectReference($reply), new IndirectReference($invalid)))->toRevision();
    }

    public function testReplyTypeNeedsItsTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires its IRT');
        $this->file('1.7', $this->page(new IndirectReference($this->annotation('Text', $this->entry('RT', new NameType('R'))))))->toRevision();
    }

    /** @return iterable<string,array{string,bool}> */
    public static function replyVersions(): iterable
    {
        yield 'IRT requires PDF1.5' => ['1.4', false];
        yield 'RT requires PDF1.6' => ['1.5', true];
    }

    #[DataProvider('replyVersions')]
    public function testRawRepliesObserveTheirFeatureVersion(string $version, bool $type): void
    {
        $primary = $this->annotation();
        $reply = $this->annotation('Text', $this->entry('IRT', new IndirectReference($primary)), ...($type ? [$this->entry('RT', new NameType('R'))] : []));
        $this->expectException(\LogicException::class);
        $this->file($version, $this->page(new IndirectReference($primary), new IndirectReference($reply)))->toRevision();
    }

    #[DataProvider('editions')]
    public function testPopupCannotAppearWithoutMarkupPopupAssociation(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot appear alone');
        $this->file($version, $this->page(new IndirectReference($this->annotation('Popup'))))->toRevision();
    }

    #[DataProvider('editions')]
    public function testPopupParentEntryAndSeparateAnnotsOccurrenceRemainOptional(string $version): void
    {
        $popup = $this->annotation('Popup');
        $markup = $this->annotation('Text', $this->entry('Popup', new IndirectReference($popup)));
        self::assertStringContainsString('/Subtype /Popup', $this->file($version, $this->page(new IndirectReference($markup)))->toPdf());
        self::assertStringContainsString('/Subtype /Popup', $this->file($version, $this->page(new IndirectReference($markup), new IndirectReference($popup)))->toPdf());
    }

    /** @return iterable<string,array{string}> */
    public static function popupForbiddenEntries(): iterable
    {
        yield 'appearance' => ['AP'];
        yield 'action' => ['A'];
        yield 'additional actions' => ['AA'];
    }

    #[DataProvider('popupForbiddenEntries')]
    public function testPopupHasNeitherAppearanceNorOwnActions(string $key): void
    {
        $popup = $this->annotation('Popup', $this->entry($key, new DictionaryType()));
        $parent = $this->annotation('Text', $this->entry('Popup', new IndirectReference($popup)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no appearance stream');
        $this->file('1.7', $this->page(new IndirectReference($parent), new IndirectReference($popup)))->toRevision();
    }

    public function testPopupParentWhenPresentAgreesWithItsMarkupAssociation(): void
    {
        $other = $this->annotation();
        $popup = $this->annotation('Popup', $this->entry('Parent', new IndirectReference($other)));
        $parent = $this->annotation('Text', $this->entry('Popup', new IndirectReference($popup)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parent agrees');
        $this->file('1.7', $this->page(new IndirectReference($parent), new IndirectReference($other)))->toRevision();
    }

    public function testWrongPageEntryIsNotAcceptedJustBecauseBothPagesExist(): void
    {
        $other = $this->page();
        $annotation = $this->annotation('Text', $this->entry('P', new IndirectReference($other)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('containing its Annots');
        $this->file('1.7', $this->page(new IndirectReference($annotation)), $other)->toRevision();
    }

    public function testFdfStyleReplyOutsideActualPageAnnotsIsNotMisclassified(): void
    {
        $fdf = new DictionaryType($this->entry('Annots', new ArrayType($this->annotation('Text', $this->entry('IRT', new TextString('external-name'))))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page(new NullType())), $this->entry('PrivateFdf', $fdf));
        self::assertStringContainsString('(external-name)', (new FileStructure(new FileHeader(), new FileBody($catalog)))->toPdf());
    }

    public function testIncrementalAnnotationAliasUsesFrozenEffectiveReplyTarget(): void
    {
        $target = $this->annotation();
        $reply = $this->annotation('Text', $this->entry('IRT', new IndirectReference($target)));
        $first = $this->page(new IndirectReference($target), new IndirectReference($reply));
        $second = $this->page();
        $base = $this->file('1.7', $first, $second)->toRevision();
        $firstSnapshot = $base->binding($first)->dictionary ?? throw new \LogicException();
        $secondSnapshot = $base->binding($second)->dictionary ?? throw new \LogicException();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same effective page');
        (new IncrementalUpdate($base, new FileBody(
            new IndirectObject($firstSnapshot->with($this->entry('Annots', new ArrayType(new IndirectReference($target)))), $base->binding($first)->identifier),
            new IndirectObject($secondSnapshot->with($this->entry('Annots', new ArrayType(new IndirectReference($reply)))), $base->binding($second)->identifier),
        )))->toRevision();
    }
}
