<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\RealType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native and frozen page attributes, retained ISO1 Table30 and ISO2 Table31. */
final class PageAttributesGraphTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function page(Entry ...$entries): PageObject
    {
        return new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, ...$entries);
    }

    private function file(PageTreeNode $pages, string $version = '2.0', Entry ...$entries): FileStructure
    {
        return new FileStructure(new FileHeader($version), new FileBody(new DocumentCatalog($pages, ...$entries)));
    }

    /** @return iterable<string,array{string,BasicType}> */
    public static function invalidValues(): iterable
    {
        yield 'whole-valued real rotation' => ['Rotate', new RealType(90)];
        yield 'fractional rotation' => ['Rotate', new NumericType(90.5)];
        yield 'zero user unit' => ['UserUnit', new IntegerType(0)];
        yield 'negative user unit' => ['UserUnit', new NumericType(-0.1)];
        yield 'wrong user unit type' => ['UserUnit', new NameType('One')];
        yield 'unknown tab order' => ['Tabs', new NameType('Bad')];
        yield 'wrong tab order type' => ['Tabs', new IntegerType(1)];
        foreach (['BleedBox', 'TrimBox', 'ArtBox'] as $key) {
            yield $key . ' cardinality' => [$key, new ArrayType(new IntegerType(0))];
            yield $key . ' coordinate type' => [$key, new ArrayType(new IntegerType(0), new IntegerType(0), new NameType('Bad'), new IntegerType(100))];
        }
    }

    #[DataProvider('invalidValues')]
    public function testNativePageRejectsInvalidOptionalAttributes(string $key, BasicType $value): void
    {
        $this->expectException(\LogicException::class);
        $this->file((new PageTreeNode())->addKid($this->page($this->entry($key, $value))))->toRevision();
    }

    #[DataProvider('invalidValues')]
    public function testIncrementalGenericReplacementCannotBypassPageAttributes(string $key, BasicType $value): void
    {
        $page = $this->page();
        $base = $this->file((new PageTreeNode())->addKid($page))->toRevision();
        $record = $base->binding($page);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing test page.'))->with($this->entry($key, $value));
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testInheritedRotationUsesIntegerSyntaxAndNearestAncestor(): void
    {
        $pages = new PageTreeNode($this->entry('Rotate', new RealType(90)));
        $pages->addKid($this->page($this->entry('Rotate', new IntegerType(-450))));
        self::assertStringContainsString('/Rotate -450', $this->file($pages)->toPdf());
        $pages = new PageTreeNode($this->entry('Rotate', new RealType(90)));
        $pages->addKid($this->page());
        $this->expectException(\LogicException::class);
        $this->file($pages)->toRevision();
    }

    /** @return iterable<string,array{string,BasicType,string}> */
    public static function prematureFeatures(): iterable
    {
        yield 'production box before 1.3' => ['BleedBox', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), '1.2'];
        yield 'tabs before 1.5' => ['Tabs', new NameType('S'), '1.4'];
        yield 'user unit before 1.6' => ['UserUnit', new NumericType(1.5), '1.5'];
        yield 'annotation order before 2.0' => ['Tabs', new NameType('A'), '1.7'];
        yield 'widget order before 2.0' => ['Tabs', new NameType('W'), '1.7'];
    }

    #[DataProvider('prematureFeatures')]
    public function testPageFeaturesRequireTheirEffectiveVersion(string $key, BasicType $value, string $version): void
    {
        $this->expectException(\LogicException::class);
        $this->file((new PageTreeNode())->addKid($this->page($this->entry($key, $value))), $version)->toRevision();
    }

    public function testCatalogUpgradePermitsPdf20TabsAndIndirectAttributesRemainValid(): void
    {
        $page = $this->page(
            $this->entry('Tabs', new IndirectReference(new NameType('W'))),
            $this->entry('UserUnit', new IndirectReference(new NumericType(100000))),
            $this->entry('Rotate', new IndirectReference(new IntegerType(-90))),
            $this->entry('TrimBox', new IndirectReference(new ArrayType(new IndirectReference(new IntegerType(100)), new IntegerType(100), new IntegerType(0), new IntegerType(0)))),
            $this->entry('ArtBox', new NullType()),
        );
        $pdf = $this->file((new PageTreeNode())->addKid($page), '1.7', $this->entry('Version', new NameType('2.0')))->toPdf();
        self::assertStringContainsString('/Version /2.0', $pdf);
        self::assertStringContainsString('100000', $pdf);
    }

    public function testOptionalNoninheritableAttributesOnParentAreNotAppliedToPage(): void
    {
        $pages = new PageTreeNode($this->entry('UserUnit', new IntegerType(0)), $this->entry('Tabs', new NameType('Bad')), $this->entry('TrimBox', new IntegerType(0)));
        $pages->addKid($this->page());
        self::assertStringContainsString('/UserUnit 0', $this->file($pages)->toPdf());
    }
}
