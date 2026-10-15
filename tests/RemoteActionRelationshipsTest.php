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
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Retained ISO1 12.6.4.3–4 pp427–429; ISO2 pp527–530. */
final class RemoteActionRelationshipsTest extends TestCase
{
    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function page(): PageObject
    {
        return new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100)), new ResourceDictionary());
    }

    private function action(string $kind, Entry ...$entries): DictionaryType
    {
        return new DictionaryType($this->e('S', new NameType($kind)), ...$entries);
    }

    private function file(DictionaryType $action, string $version = '1.7', ?PageObject $page = null): FileStructure
    {
        return new FileStructure(new FileHeader($version), new FileBody(new DocumentCatalog((new PageTreeNode())->addKid($page ?? $this->page()), $this->e('OpenAction', new IndirectReference($action)))));
    }

    /** @return iterable<string,array{string}> */
    public static function editions(): iterable
    {
        yield 'ISO1' => ['1.7'];
        yield 'ISO2' => ['2.0'];
    }

    #[DataProvider('editions')]
    public function testRemoteDestinationRejectsTheCurrentDocumentPageReference(string $version): void
    {
        $page = $this->page();
        $action = $this->action('GoToR', $this->e('F', new TextString('remote.pdf')), $this->e('D', new ArrayType(new IndirectReference($page), new NameType('Fit'))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zero-based page number');
        $this->file($action, $version, $page)->toRevision();
    }

    #[DataProvider('editions')]
    public function testRemotePageNumbersDoNotUseLocalBoundsOrLocalNamedDestinationLookup(string $version): void
    {
        foreach ([new ArrayType(new IntegerType(234), new NameType('XYZ'), new NullType(), new NumericType(0), new NumericType(2)), new NameType('remote-name'), new TextString('remote-name')] as $destination) {
            $action = $this->action('GoToR', $this->e('F', new TextString('remote.pdf')), $this->e('D', $destination));
            self::assertStringContainsString('/GoToR', $this->file($action, $version)->toPdf());
        }
    }

    public function testRemoteDPageNumberAndPdf20SDStructureIdentifierKeepDifferentMeaning(): void
    {
        $action = $this->action('GoToR', $this->e('F', new TextString('remote.pdf')), $this->e('D', new ArrayType(new IntegerType(0), new NameType('Fit'))), $this->e('SD', new ArrayType(new TextString('structure-id'), new NameType('Fit'))));
        self::assertStringContainsString('/SD [(structure-id) /Fit]', $this->file($action, '2.0')->toPdf());
    }

    #[DataProvider('editions')]
    public function testEmbeddedActionWithoutFileRequiresItsTargetPath(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a T target path');
        $this->file($this->action('GoToE', $this->e('D', new TextString('target'))), $version)->toRevision();
    }

    #[DataProvider('editions')]
    public function testEmbeddedExternalRootMakesTargetPathOptional(string $version): void
    {
        self::assertStringContainsString('/GoToE', $this->file($this->action('GoToE', $this->e('F', new TextString('root.pdf')), $this->e('D', new ArrayType(new IntegerType(0), new NameType('Fit')))), $version)->toPdf());
    }

    public function testEmbeddedPathCanClimbToParentThenSelectNamedChildWithoutImplicitIo(): void
    {
        $child = new DictionaryType($this->e('R', new NameType('C')), $this->e('N', new TextString('embedded-name')));
        $parent = new DictionaryType($this->e('R', new NameType('P')), $this->e('T', new IndirectReference($child)));
        $action = $this->action('GoToE', $this->e('D', new NameType('target')), $this->e('T', new IndirectReference($parent)));
        self::assertStringContainsString('/N (embedded-name)', $this->file($action)->toPdf());
    }

    public function testAttachmentPathPermitsExternalPageAndAnnotationSelectorsWithoutLocalBounds(): void
    {
        foreach ([[new IntegerType(120), new IntegerType(450)], [new TextString('remote-page'), new TextString('attachment-name')]] as [$page, $annotation]) {
            $target = new DictionaryType($this->e('R', new NameType('C')), $this->e('P', $page), $this->e('A', $annotation));
            $action = $this->action('GoToE', $this->e('F', new TextString('root.pdf')), $this->e('D', new NameType('target')), $this->e('T', $target));
            self::assertStringContainsString('/GoToE', $this->file($action)->toPdf());
        }
    }

    public function testExplicitDestinationParameterRulesAreComposedForRemoteViews(): void
    {
        $action = $this->action('GoToR', $this->e('F', new TextString('remote.pdf')), $this->e('D', new ArrayType(new IntegerType(0), new NameType('FitR'), new NullType(), new NumericType(0), new NumericType(1), new NumericType(1))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('coordinates');
        $this->file($action)->toRevision();
    }

    /** @return iterable<string,array{DictionaryType}> */
    public static function invalidTargets(): iterable
    {
        $e = static fn (string $key, BasicType $value): Entry => new Entry(new NameType($key), $value);
        yield 'missing relation' => [new DictionaryType()];
        yield 'parent cannot name child' => [new DictionaryType($e('R', new NameType('P')), $e('N', new TextString('name')))];
        yield 'child needs a selector' => [new DictionaryType($e('R', new NameType('C')))];
        yield 'N and P are exclusive' => [new DictionaryType($e('R', new NameType('C')), $e('N', new TextString('name')), $e('P', new IntegerType(0)))];
        yield 'attachment needs A' => [new DictionaryType($e('R', new NameType('C')), $e('P', new IntegerType(0)))];
        yield 'attachment page is nonnegative' => [new DictionaryType($e('R', new NameType('C')), $e('P', new IntegerType(-1)), $e('A', new IntegerType(0)))];
        yield 'attachment index is nonnegative' => [new DictionaryType($e('R', new NameType('C')), $e('P', new IntegerType(0)), $e('A', new IntegerType(-1)))];
    }

    #[DataProvider('invalidTargets')]
    public function testEmbeddedTargetSelectorsFollowTheirRelationship(DictionaryType $target): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->file($this->action('GoToE', $this->e('D', new TextString('target')), $this->e('T', new IndirectReference($target))))->toRevision();
    }

    public function testRemoteFileAndDestinationAreRequiredEvenForGenericActions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires its F');
        $this->file($this->action('GoToR', $this->e('D', new TextString('target'))))->toRevision();
    }

    public function testLocalDestinationRetainsItsCurrentPageReference(): void
    {
        $page = $this->page();
        $action = $this->action('GoTo', $this->e('D', new ArrayType(new IndirectReference($page), new NameType('Fit'))));
        $revision = $this->file($action, page: $page)->toRevision();
        self::assertStringContainsString('/D [' . $revision->binding($page)->identifier . ' R /Fit]', $revision->bytes);
    }

    public function testPrivateDictionaryIsNotGuessedToBeAnActiveAction(): void
    {
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page()), $this->e('Private', $this->action('GoToE')));
        self::assertStringContainsString('/Private << /S /GoToE', (new FileStructure(new FileHeader(), new FileBody($catalog)))->toPdf());
    }

    public function testFrozenRemoteActionIsRevalidatedWhenItsDestinationIsReplaced(): void
    {
        $page = $this->page();
        $destination = new ArrayType(new IntegerType(0), new NameType('Fit'));
        $action = $this->action('GoToR', $this->e('F', new TextString('remote.pdf')), $this->e('D', new IndirectReference($destination)));
        $base = $this->file($action, page: $page)->toRevision();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zero-based page number');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject(new ArrayType(new IndirectReference($page), new NameType('Fit')), $base->binding($destination)->identifier))))->toRevision();
    }

    public function testFrozenTargetPathCannotAcquireAnIndirectCycle(): void
    {
        $target = new DictionaryType($this->e('R', new NameType('P')));
        $base = $this->file($this->action('GoToE', $this->e('D', new TextString('target')), $this->e('T', new IndirectReference($target))))->toRevision();
        $replacement = $target->with($this->e('T', new IndirectReference($target)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot cycle');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($target)->identifier))))->toRevision();
    }
}
