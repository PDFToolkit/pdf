<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
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
use PDF\Syntax\FileStructure\FileRevision;
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
use PDF\Syntax\Object\RealType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Effective outline writer invariants, including immutable incremental snapshots. */
final class OutlineGraphTest extends TestCase
{
    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function file(OutlineDictionary $outline, string $version = '2.0'): FileStructure
    {
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary());
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $this->e('Outlines', new IndirectReference($outline)));
        return new FileStructure(new FileHeader($version), new FileBody($catalog));
    }

    /** @return array{FileRevision,OutlineDictionary,OutlineItem,OutlineItem,OutlineItem} */
    private function hierarchy(bool $open = true): array
    {
        $child = new OutlineItem(new TextString('Child'));
        $first = (new OutlineItem(new TextString('First'), expanded: $open))->addItem($child);
        $last = new OutlineItem(new TextString('Last'), expanded: $open);
        $root = (new OutlineDictionary())->addItem($first)->addItem($last);
        return [$this->file($root)->toRevision(), $root, $first, $last, $child];
    }

    /** @return iterable<string,array{string,BasicType}> */
    public static function invalidAttributes(): iterable
    {
        yield 'reserved style bit' => ['F', new IntegerType(4)];
        yield 'negative style bits' => ['F', new IntegerType(-1)];
        yield 'real style flags' => ['F', new RealType(1)];
        yield 'colour length' => ['C', new ArrayType(new IntegerType(0))];
        yield 'colour maximum' => ['C', new ArrayType(new IntegerType(2), new IntegerType(0), new IntegerType(0))];
        yield 'colour minimum' => ['C', new ArrayType(new NumericType(-0.1), new IntegerType(0), new IntegerType(0))];
        yield 'colour type' => ['C', new ArrayType(new NameType('Red'), new IntegerType(0), new IntegerType(0))];
        yield 'direct SE' => ['SE', new DictionaryType()];
        yield 'stream SE' => ['SE', new IndirectReference(new StreamObject(''))];
    }

    #[DataProvider('invalidAttributes')]
    public function testNativeOutlineOptionalAttributesAreValidated(string $key, BasicType $value): void
    {
        $outline = (new OutlineDictionary())->addItem(new OutlineItem(new TextString('Invalid'), null, true, $this->e($key, $value)));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($outline)->toRevision();
    }

    /** @return iterable<string,array{string,string}> */
    public static function brokenGraphs(): iterable
    {
        foreach (['First', 'Last', 'Count'] as $key) {
            yield 'root invalid ' . $key => ['root', $key];
        }
        foreach (['Parent', 'Prev', 'Next', 'First', 'Last', 'Count', 'Title'] as $key) {
            yield 'first invalid ' . $key => ['first', $key];
        }
        yield 'last missing Prev' => ['last', 'Prev'];
        yield 'leaf false descendants' => ['last', 'Count'];
        yield 'child missing Parent' => ['child', 'Parent'];
    }

    #[DataProvider('brokenGraphs')]
    public function testReplacementOfFrozenOutlineCannotBreakItsHierarchy(string $owner, string $key): void
    {
        [$base, $root, $first, $last, $child] = $this->hierarchy();
        $target = match ($owner) {
            'root' => $root, 'first' => $first, 'last' => $last, default => $child
        };
        $record = $base->binding($target);
        $value = match ($key) {
            'Count' => new IntegerType(999),
            'Title' => new NameType('NotText'),
            'Type' => new NameType('NotOutlines'),
            'Prev' => $owner === 'first' ? new IndirectReference($last) : new NullType(),
            'Next' => new IntegerType(1),
            default => new NullType(),
        };
        $changed = ($record->dictionary ?? throw new \LogicException('Missing test outline.'))->with($this->e($key, $value));
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testRootCountIsRequiredForOpenDescendants(): void
    {
        [$base, $root] = $this->hierarchy();
        $record = $base->binding($root);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing test outline.'))->without('Count');
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testWrongLastIdentityIsRejected(): void
    {
        [$base, $root, $first] = $this->hierarchy();
        $record = $base->binding($root);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing test outline.'))->with($this->e('Last', new IndirectReference($first)));
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testSiblingCycleIsRejectedEvenWhenAllReferencesAreIndirect(): void
    {
        [$base, , $first, $last] = $this->hierarchy();
        $record = $base->binding($last);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing test outline.'))->with($this->e('Next', new IndirectReference($first)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testFrozenOutlineKeepsEmittedCountsAfterBuilderChanges(): void
    {
        [$base, $root, $first] = $this->hierarchy();
        $first->addItem(new OutlineItem(new TextString('Not emitted')));
        $root->addItem(new OutlineItem(new TextString('Also not emitted')));
        $next = (new IncrementalUpdate($base, new FileBody()))->toRevision();
        self::assertSame($base->binding($root)->dictionary, $next->binding($root)->dictionary);
        self::assertSame($base->bytes, substr($next->bytes, 0, strlen($base->bytes)));
    }

    public function testClosedOutlineRootOmitsCountAndNegativeItemCountRemainsValid(): void
    {
        [$base, $root, $first] = $this->hierarchy(false);
        self::assertNull($base->binding($root)->dictionary?->get('Count'));
        self::assertSame(-1, $base->binding($first)->dictionary?->get('Count')?->value);
        self::assertNotEmpty((new IncrementalUpdate($base, new FileBody()))->toRevision()->bytes);
    }

    public function testDestAndActionCannotBeCombinedInAnEffectiveItem(): void
    {
        [$base, , $first] = $this->hierarchy();
        $record = $base->binding($first);
        $changed = ($record->dictionary ?? throw new \LogicException('Missing test outline.'))->with($this->e('Dest', new TextString('target')))->with($this->e('A', new DictionaryType($this->e('S', new NameType('Named')), $this->e('N', new NameType('NextPage')))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testValidIndirectOptionalValuesAndEmptyOutline(): void
    {
        $root = (new OutlineDictionary())->addItem(new OutlineItem(
            new TextString('Styled'),
            null,
            true,
            $this->e('C', new IndirectReference(new ArrayType(new IndirectReference(new NumericType(0.5)), new IntegerType(0), new IntegerType(1)))),
            $this->e('F', new IndirectReference(new IntegerType(3))),
        ));
        self::assertStringContainsString('/Title (Styled)', $this->file($root)->toPdf());
        self::assertStringContainsString('/Type /Outlines', $this->file(new OutlineDictionary())->toPdf());
    }

    public function testColourVersionGateUsesEffectiveDocumentVersion(): void
    {
        $root = (new OutlineDictionary())->addItem(new OutlineItem(new TextString('Styled'), null, true, $this->e('F', new IntegerType(1))));
        $this->expectException(\LogicException::class);
        $this->file($root, '1.3')->toRevision();
    }
}
