<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Dictionary\OutlineDictionary;
use PDF\Document\Destinations\Fit;
use PDF\Document\Destinations\XYZ;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\NumberTree;
use PDF\Syntax\DataStructure\NumberTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Pages;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageDictionary\Rotate;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class DocumentStructureTest extends TestCase
{
    private function page(): PageObject
    {
        return new PageObject(
            new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
            new ResourceDictionary(),
        );
    }

    public function testPageCountCountsDescendantLeavesAndSetsParents(): void
    {
        $leaf = $this->page();
        $branch = (new PageTreeNode())->addKid($leaf)->addKid($this->page());
        $root = (new PageTreeNode())->addKid($branch)->addKid($this->page());
        self::assertSame(3, $root->pageCount());
        self::assertSame(3, $root->get('Count')->value);
        self::assertSame($branch, $leaf->parent());
        self::assertSame($root, $branch->parent());
        self::assertFalse($root->has('Parent'));
        self::assertSame($root, $branch->get('Parent')->target());
    }

    public function testPageTreeRejectsAncestorCycle(): void
    {
        $root = new PageTreeNode();
        $branch = new PageTreeNode();
        $root->addKid($branch);
        $this->expectException(\InvalidArgumentException::class);
        $branch->addKid($root);
    }

    public function testPageCanBelongToOnlyOneParent(): void
    {
        $page = $this->page();
        (new PageTreeNode())->addKid($page);
        $this->expectException(\InvalidArgumentException::class);
        (new PageTreeNode())->addKid($page);
    }

    public function testCatalogRejectsNonRootPages(): void
    {
        $branch = new PageTreeNode();
        (new PageTreeNode())->addKid($branch);
        $this->expectException(\InvalidArgumentException::class);
        new DocumentCatalog($branch);
    }

    public function testCatalogPagesEntryRejectsWrongReferenceTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Pages(new IndirectReference(new DictionaryType()));
    }

    public function testCompleteGraphSerializesNestedPagesAndContentsAsIndirectObjects(): void
    {
        $page = new PageObject(
            new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100)),
            new ResourceDictionary(),
            new StreamObject(''),
        );
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid((new PageTreeNode())->addKid($page)));
        $file = new FileStructure(new FileHeader(), new FileBody($catalog));
        $pdf = $file->toPdf();
        self::assertSame($pdf, $file->toPdf());
        self::assertStringContainsString('/Type /Catalog', $pdf);
        self::assertStringContainsString('/Type /Page', $pdf);
        self::assertMatchesRegularExpression('~/Contents \d+ 0 R~', $pdf);
        self::assertMatchesRegularExpression('~/Parent \d+ 0 R~', $pdf);
        self::assertStringContainsString('/Length 0', $pdf);
        self::assertStringContainsString("\nstartxref\n", $pdf);
    }

    public function testContentsArrayRequiresIndirectStreams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PageObject(
            new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100)),
            new ResourceDictionary(),
            new ArrayType(new StreamObject('')),
        );
    }

    public function testTextStringEncodesUnicodeAsUtf16BigEndian(): void
    {
        self::assertSame('ASCII', (new TextString('ASCII'))->value);
        self::assertSame("\xfe\xff\x00\xe9\xd8\x3d\xde\x42", (new TextString('é🙂'))->value);
    }

    public function testDateKeepsItsTimezoneOffset(): void
    {
        self::assertSame('D:20261012235900+02\'00\'', (new Date(new \DateTimeImmutable('2026-10-12 23:59:00+02:00')))->value);
        self::assertSame('D:20261012235900Z', (new Date(new \DateTimeImmutable('2026-10-12 23:59:00+00:00')))->value);
    }

    public function testNameTreeSortsByteKeysAndBranchComputesLimits(): void
    {
        $left = new NameTree(
            new NameTreeEntry(new LiteralString('b'), new IntegerType(2)),
            new NameTreeEntry(new LiteralString('a'), new IntegerType(1)),
        );
        self::assertSame('a', $left->get('Names')->items[0]->value);
        $right = new NameTree(new NameTreeEntry(new LiteralString('z'), new IntegerType(3)));
        $root = NameTree::branch($right, $left);
        self::assertFalse($root->has('Limits'));
        $child = $root->get('Kids')->items[0]->target();
        self::assertSame('a', $child->get('Limits')->items[0]->value);
        self::assertSame('b', $child->get('Limits')->items[1]->value);
        self::assertFalse($left->has('Limits'));
    }

    public function testNameTreeRejectsDuplicateKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NameTree(
            new NameTreeEntry(new LiteralString('a'), new IntegerType(1)),
            new NameTreeEntry(new LiteralString('a'), new IntegerType(2)),
        );
    }

    public function testNumberTreeRejectsOverlappingChildRanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NumberTree::branch(
            new NumberTree(new NumberTreeEntry(new IntegerType(0), new LiteralString('a')), new NumberTreeEntry(new IntegerType(5), new LiteralString('b'))),
            new NumberTree(new NumberTreeEntry(new IntegerType(4), new LiteralString('c'))),
        );
    }

    public function testNumberTreeSortsNumerically(): void
    {
        $tree = new NumberTree(
            new NumberTreeEntry(new IntegerType(12), new LiteralString('twelve')),
            new NumberTreeEntry(new IntegerType(2), new LiteralString('two')),
        );
        self::assertSame(2, $tree->get('Nums')->items[0]->value);
        self::assertSame(12, $tree->get('Nums')->items[2]->value);
    }

    public function testDestinationUsesAnIndirectPageAndNullCoordinates(): void
    {
        $page = $this->page();
        $destination = new XYZ($page, null, new NumericType(42));
        self::assertSame($page, $destination->items[0]->target());
        self::assertSame('XYZ', $destination->items[1]->value);
        self::assertInstanceOf(\PDF\Syntax\Object\NullType::class, $destination->items[2]);
        self::assertSame(42, $destination->items[3]->value);
    }

    public function testOutlineCountAndSiblingLinksFollowHierarchy(): void
    {
        $first = (new OutlineItem(new TextString('First'), new Fit($this->page()), false))
            ->addItem(new OutlineItem(new TextString('Hidden child')));
        $second = new OutlineItem(new TextString('Second'));
        $outline = (new OutlineDictionary())->addItem($first)->addItem($second);
        self::assertSame(2, $outline->get('Count')->value);
        self::assertSame(-1, $first->get('Count')->value);
        self::assertSame($second, $first->get('Next')->target());
        self::assertSame($first, $second->get('Prev')->target());
        self::assertSame($outline, $first->get('Parent')->target());
    }

    public function testRotateRejectsNonQuarterTurn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Rotate(new IntegerType(45));
    }

    public function testResourcesRejectWrongDictionaryValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResourceDictionary(new Entry(new NameType('Font'), new NumericType(12)));
    }

    public function testPageInheritsMediaBoxAndResourcesFromNearestAncestor(): void
    {
        $box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(200));
        $resources = new ResourceDictionary();
        $page = new PageObject();
        $branch = (new PageTreeNode(new \PDF\Syntax\DocumentStructure\PageDictionary\Resources($resources)))->addKid($page);
        $root = (new PageTreeNode(new \PDF\Syntax\DocumentStructure\PageDictionary\MediaBox($box)))->addKid($branch);
        self::assertSame($box, $page->effectiveMediaBox());
        self::assertSame($resources, $page->effectiveResources());
        self::assertFalse($page->has('MediaBox'));
        self::assertFalse($page->has('Resources'));
        self::assertStringContainsString('/Type /Page', (new FileStructure(new FileHeader(), new FileBody(new DocumentCatalog($root))))->toPdf());
    }

    public function testUnownedPageAttachmentFailsSerialization(): void
    {
        $page = $this->page();
        $page->attachTo(new PageTreeNode());
        $this->expectException(\LogicException::class);
        iterator_to_array($page->entries());
    }

    public function testAttachedPageCannotBeCloned(): void
    {
        $page = $this->page();
        (new PageTreeNode())->addKid($page);
        $this->expectException(\LogicException::class);
        $copy = clone $page;
        self::assertNotSame($page, $copy);
    }

    public function testRequiredCatalogEntryCannotBeRemoved(): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $this->expectException(\InvalidArgumentException::class);
        $catalog->without('Pages');
    }

    public function testCatalogRejectsPageRootReattachedAfterConstruction(): void
    {
        $pages = new PageTreeNode();
        $catalog = new DocumentCatalog($pages);
        (new PageTreeNode())->addKid($pages);
        $this->expectException(\LogicException::class);
        iterator_to_array($catalog->entries());
    }

    public function testOutlineCountsVisibleDescendantsRecursively(): void
    {
        $grandChild = new OutlineItem(new TextString('grandchild'));
        $closed = (new OutlineItem(new TextString('closed'), null, false))->addItem($grandChild);
        $open = (new OutlineItem(new TextString('open')))->addItem($closed)->addItem(new OutlineItem(new TextString('sibling')));
        $root = (new OutlineDictionary())->addItem($open);
        self::assertSame(3, $root->get('Count')->value);
        self::assertSame(2, $open->get('Count')->value);
        self::assertSame(-1, $closed->get('Count')->value);
    }
}
