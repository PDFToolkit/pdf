<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EffectivePageGraphTest extends TestCase
{
    private function entry(string $name, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }

    private function file(DocumentCatalog $catalog): FileStructure
    {
        return new FileStructure(new FileHeader(), new FileBody($catalog), new IndirectReference($catalog));
    }

    private function box(): Rectangle
    {
        return new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(300), new IntegerType(400));
    }

    public function testInheritedAttributesAcceptOppositeDiagonalOrderAndNegativeRotation(): void
    {
        $parent = new PageTreeNode($this->entry('MediaBox', new Rectangle(new IntegerType(300), new IntegerType(400), new IntegerType(0), new IntegerType(0))), $this->entry('Resources', new ResourceDictionary()), $this->entry('Rotate', new IntegerType(-450)));
        $parent->addKid(new PageObject(null, null, null, $this->entry('CropBox', new NullType())));
        self::assertStringContainsString('/Rotate -450', $this->file(new DocumentCatalog($parent))->toPdf());
    }

    public function testNumericBoxCoordinatesCanBeIndirectObjects(): void
    {
        $parent = new PageTreeNode($this->entry('MediaBox', $this->box()), $this->entry('Resources', new ResourceDictionary()));
        $parent->addKid(new PageObject());
        $base = $this->file(new DocumentCatalog($parent))->toRevision();
        $snapshot = $base->binding($parent)->snapshot;
        self::assertInstanceOf(DictionaryType::class, $snapshot);
        $changed = $snapshot->with($this->entry('MediaBox', new ArrayType(new IndirectReference(new IntegerType(0)), new IntegerType(0), new IntegerType(300), new IntegerType(400))));
        $next = (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $base->binding($parent)->identifier))))->toRevision();
        self::assertStringContainsString('/MediaBox [', substr($next->bytes, strlen($base->bytes)));
    }

    /** @return iterable<string, array{string, \PDF\Syntax\FileStructure\BasicType}> */
    public static function invalidAttributes(): iterable
    {
        yield 'resources are a dictionary' => ['Resources', new IntegerType(7)];
        yield 'rectangle has four coordinates' => ['MediaBox', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(400))];
        yield 'rectangle coordinates are numbers' => ['CropBox', new ArrayType(new IntegerType(0), new NameType('bad'), new IntegerType(300), new IntegerType(400))];
        yield 'rotation is a multiple of 90' => ['Rotate', new IntegerType(15)];
    }

    #[DataProvider('invalidAttributes')]
    public function testGenericReplacementOfPageIsValidatedAgainstTheActualEffectiveGraph(string $key, \PDF\Syntax\FileStructure\BasicType $value): void
    {
        $page = new PageObject($this->box(), new ResourceDictionary());
        $base = $this->file(new DocumentCatalog((new PageTreeNode())->addKid($page)))->toRevision();
        $snapshot = $base->binding($page)->snapshot;
        self::assertInstanceOf(DictionaryType::class, $snapshot);
        $changed = $snapshot->with($this->entry($key, $value));
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $base->binding($page)->identifier))))->toRevision();
    }

    public function testHistoricalPageCannotLoseItsOnlyResourcesThroughParentReplacement(): void
    {
        $parent = new PageTreeNode($this->entry('MediaBox', $this->box()), $this->entry('Resources', new ResourceDictionary()));
        $parent->addKid(new PageObject());
        $base = $this->file(new DocumentCatalog($parent))->toRevision();
        $snapshot = $base->binding($parent)->snapshot;
        self::assertInstanceOf(DictionaryType::class, $snapshot);
        $changed = $snapshot->without('Resources');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Resources');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $base->binding($parent)->identifier))))->toRevision();
    }

    public function testHistoricalInheritedValuesComeFromEmittedSnapshot(): void
    {
        $parent = new PageTreeNode($this->entry('MediaBox', $this->box()), $this->entry('Resources', new ResourceDictionary()));
        $parent->addKid(new PageObject());
        $base = $this->file(new DocumentCatalog($parent))->toRevision();
        $parent->addKid(new PageObject());
        $next = (new IncrementalUpdate($base, new FileBody()))->toRevision();
        self::assertSame($base->bytes, substr($next->bytes, 0, strlen($base->bytes)));
        $count = $next->binding($parent)->dictionary?->get('Count');
        self::assertInstanceOf(IntegerType::class, $count);
        self::assertSame(1, $count->integer());
    }
}
