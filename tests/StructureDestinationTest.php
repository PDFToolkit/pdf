<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Destinations\Fit;
use PDF\Document\Navigation\DestinationDictionary;
use PDF\Document\Navigation\DestinationView;
use PDF\Document\Navigation\GoToDestination;
use PDF\Document\Navigation\LocalDestination;
use PDF\Document\Navigation\NamedDestination;
use PDF\Document\Navigation\NamedDestinationEntry;
use PDF\Document\Navigation\NamedDestinations;
use PDF\Document\Navigation\NavigationActionEffects;
use PDF\Document\Navigation\RemoteStructureDestination;
use PDF\Document\Navigation\StructureDestination;
use PDF\Document\Navigation\StructureDestinationEntry;
use PDF\Document\Navigation\StructureDestinationResolver;
use PDF\Document\ResourceDictionary;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
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
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class StructureDestinationTest extends TestCase
{
    public function testEmptyElementPgDoesNotReplaceTheFirstPageFallback(): void
    {
        [$catalog, $target, $first, $second] = $this->tree();
        $sd = new StructureDestination($target, new DestinationView(new NameType('Fit')));
        self::assertSame($second, $target->page);
        self::assertSame($first, $sd->page($catalog)->target());
        self::assertSame($first, $sd->local($catalog)->page);
    }

    public function testDfsSkipsEmptyFirstChildAndUsesTheNextContentPage(): void
    {
        [$catalog, $target, $first, $second] = $this->contentTree();
        self::assertSame($second, (new StructureDestination($target, new DestinationView(new NameType('Fit'))))->page($catalog)->target());
    }

    public function testForeignStructureElementCannotBecomeLocalTarget(): void
    {
        [$catalog] = $this->tree();
        $this->expectException(\InvalidArgumentException::class);
        (new StructureDestination(new StructureElement(new NameType('P')), new DestinationView(new NameType('Fit'))))->page($catalog);
    }

    public function testMcrAndObjrPageOverrideTheirContainingElementPage(): void
    {
        foreach (['MCR', 'OBJR'] as $kind) {
            $content = new \PDF\Syntax\Object\StreamObject(new \PDF\Syntax\ContentStreamsResources\ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties(new NameType('P'), new DictionaryType(new Entry(new NameType('MCID'), new IntegerType(0)))), new \PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent()));
            $first = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary(), $kind === 'MCR' ? $content : null, new Entry(new NameType('StructParents'), new IntegerType(0)));
            $second = $this->page();
            $pages = (new PageTreeNode())->addKid($first)->addKid($second);
            $root = new StructureTreeRoot($pages);
            $target = new StructureElement(new NameType('Sect'), page: $second);
            $root->addKid($target);
            $catalog = new DocumentCatalog($pages, new Entry(new NameType('StructTreeRoot'), new IndirectReference($root)));
            $child = $kind === 'MCR'
                ? new \PDF\Document\Structure\MarkedContentReference(new \PDF\Document\Structure\MarkedContentContainer($first, new IntegerType(0)), new \PDF\Document\Structure\MarkedContentIdentifier(new IntegerType(0)))
                : new \PDF\Document\Structure\ObjectReference($first, new DictionaryType(new Entry(new NameType('StructParent'), new IntegerType(0))), new IntegerType(0));
            $target->addKid($child);
            self::assertSame($first, (new StructureDestination($target, new DestinationView(new NameType('Fit'))))->page($catalog)->target());
        }
    }

    public function testViewArityAndNullRulesAreSharedWithPageDestinations(): void
    {
        new DestinationView(new NameType('XYZ'), new NullType(), new IntegerType(0), new NullType());
        $this->expectException(\InvalidArgumentException::class);
        new DestinationView(new NameType('FitR'), new IntegerType(0), new IntegerType(0), new NullType(), new IntegerType(100));
    }

    public function testLocalSdisWrittenWithItsIndependentFallbackAndRequiresPdf20(): void
    {
        [$catalog, $target, $first] = $this->tree();
        $action = new GoToDestination(new LocalDestination(new Fit($first)), structure: new StructureDestination($target, new DestinationView(new NameType('Fit'))));
        $catalog = $catalog->with(new Entry(new NameType('OpenAction'), $action));
        self::assertStringContainsString('/SD [', $this->file($catalog)->toPdf());
        $this->expectException(\LogicException::class);
        $this->file($catalog, '1.7')->toPdf();
    }

    public function testRemoteIdsRemainBytesAndLocalReferencesAreRejected(): void
    {
        $remote = new RemoteStructureDestination(new LiteralString("section\0id"), new DestinationView(new NameType('FitH'), new IntegerType(500)));
        StructureDestinationResolver::remote($remote);
        self::assertInstanceOf(LiteralString::class, $remote->items[0]);
        self::assertSame("section\0id", $remote->items[0]->value);
        [$catalog, $target, $first] = $this->tree();
        $action = new DictionaryType(new Entry(new NameType('S'), new NameType('GoToR')), new Entry(new NameType('F'), new LiteralString('remote.pdf')), new Entry(new NameType('D'), new ArrayType(new IntegerType(0), new NameType('Fit'))), new StructureDestinationEntry(new StructureDestination($target, new DestinationView(new NameType('Fit')))));
        $catalog = $catalog->with(new Entry(new NameType('OpenAction'), $action));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testNamedDestinationPreservesFallbackButNavigationUsesSdPage(): void
    {
        [$catalog, $target, $first] = $this->contentTree();
        $fallback = new LocalDestination(new Fit($first));
        $sd = new StructureDestination($target, new DestinationView(new NameType('Fit')));
        $named = new NamedDestinations(new NamedDestinationEntry(new LiteralString('section'), new DestinationDictionary($fallback, new StructureDestinationEntry($sd))));
        $action = new GoToDestination(new NamedDestination(new LiteralString('section')));
        $catalog = $catalog->with($named->catalogEntry())->with(new Entry(new NameType('OpenAction'), $action));
        self::assertTrue((new NavigationActionEffects())->leavesPage($action, $first, $catalog));
        $reference = (new NamedDestination(new LiteralString('section')))->resolve($catalog)->items[0];
        self::assertInstanceOf(IndirectReference::class, $reference);
        self::assertSame($first, $reference->target());
        self::assertSame([], (new \PDF\Document\Structure\WtPdfNavigationChecks())->inspect($catalog));
        self::assertStringContainsString('/SD [', $this->file($catalog)->toPdf());
    }

    public function testNamedPageDestinationWithoutSdFailsWtPdfNavigation(): void
    {
        [$catalog, $_target, $first] = $this->tree();
        $named = new NamedDestinations(new NamedDestinationEntry(new LiteralString('page'), new LocalDestination(new Fit($first))));
        $catalog = $catalog->with($named->catalogEntry());
        $findings = (new \PDF\Document\Structure\WtPdfNavigationChecks())->inspect($catalog);
        self::assertSame('8.8', $findings[0]->clause);
    }

    public function testNullOptionalSdPreservesTheNamedStructureDestination(): void
    {
        foreach ([new NullType(), new IndirectReference(new NullType())] as $null) {
            [$catalog, $target, $first] = $this->contentTree();
            $named = new NamedDestinations(new NamedDestinationEntry(new LiteralString('section'), new DestinationDictionary(new LocalDestination(new Fit($first)), new StructureDestinationEntry(new StructureDestination($target, new DestinationView(new NameType('Fit')))))));
            $action = new DictionaryType(new Entry(new NameType('S'), new NameType('GoTo')), new Entry(new NameType('D'), new LiteralString('section')), new Entry(new NameType('SD'), $null));
            $catalog = $catalog->with($named->catalogEntry())->with(new Entry(new NameType('OpenAction'), $action));
            self::assertTrue((new NavigationActionEffects())->leavesPage($action, $first, $catalog));
            self::assertSame([], (new \PDF\Document\Structure\WtPdfNavigationChecks())->inspect($catalog));
            self::assertStringContainsString('/SD [', $this->file($catalog)->toPdf());
        }
    }

    public function testWtPdfInspectsPresentationAndInheritedFieldActionContexts(): void
    {
        foreach (['presentation', 'field'] as $kind) {
            $first = $this->page();
            $action = new GoToDestination(new LocalDestination(new Fit($first)));
            if ($kind === 'presentation') {
                $sequence = (new \PDF\Document\Navigation\PresentationSequence())->addNode(new \PDF\Document\Navigation\NavigationNode($action));
                $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary(), null, $sequence->pageEntry());
                $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page)->addKid($first));
                $sequence->bindToPage($page);
            } else {
                $catalog = new DocumentCatalog((new PageTreeNode())->addKid($first), new Entry(new NameType('AcroForm'), new DictionaryType(new Entry(new NameType('Fields'), new ArrayType(new DictionaryType(new Entry(new NameType('FT'), new NameType('Tx')), new Entry(new NameType('Kids'), new ArrayType(new DictionaryType(new Entry(new NameType('AA'), new DictionaryType(new Entry(new NameType('K'), $action))))))))))));
            }
            $findings = (new \PDF\Document\Structure\WtPdfNavigationChecks())->inspect($catalog);
            self::assertCount(1, $findings);
            self::assertSame('8.8', $findings[0]->clause);
        }
    }

    public function testWtPdfDoesNotAcceptSdWithoutItsRequiredPageFallback(): void
    {
        [$catalog, $target] = $this->tree();
        $action = new DictionaryType(new Entry(new NameType('S'), new NameType('GoTo')), new StructureDestinationEntry(new StructureDestination($target, new DestinationView(new NameType('Fit')))));
        $findings = (new \PDF\Document\Structure\WtPdfNavigationChecks())->inspect($catalog->with(new Entry(new NameType('OpenAction'), $action)));
        self::assertCount(1, $findings);
        self::assertStringContainsString('required D', $findings[0]->message);
    }

    public function testRemoteAndEmbeddedSdDoNotResolveOrLoadTheirTargetDocument(): void
    {
        foreach (['GoToR', 'GoToE'] as $subtype) {
            [$catalog] = $this->tree();
            $action = new DictionaryType(new Entry(new NameType('S'), new NameType($subtype)), new Entry(new NameType('F'), new LiteralString('https://invalid.example/not-loaded.pdf')), new Entry(new NameType('D'), new ArrayType(new IntegerType(0), new NameType('Fit'))), new StructureDestinationEntry(new RemoteStructureDestination(new LiteralString('section'), new DestinationView(new NameType('Fit')))));
            $catalog = $catalog->with(new Entry(new NameType('OpenAction'), $action));
            self::assertStringContainsString('/SD [(section)', $this->file($catalog)->toPdf());
        }
    }

    public function testFrozenRawReplacementCannotConvertLocalSdToAnExternalId(): void
    {
        [$catalog, $target, $first] = $this->tree();
        $action = new GoToDestination(new LocalDestination(new Fit($first)), structure: new StructureDestination($target, new DestinationView(new NameType('Fit'))));
        $catalog = $catalog->with(new Entry(new NameType('OpenAction'), new IndirectReference($action)));
        $base = $this->file($catalog)->toRevision();
        $snapshot = $base->binding($action)->dictionary ?? throw new \LogicException('An action has a snapshot.');
        $replacement = $snapshot->with(new Entry(new NameType('SD'), new ArrayType(new LiteralString('external-id'), new NameType('Fit'))));
        $this->expectException(\InvalidArgumentException::class);
        (new \PDF\Syntax\FileStructure\IncrementalUpdate($base, new FileBody(new \PDF\Syntax\Object\IndirectObject($replacement, $base->binding($action)->identifier))))->toPdf();
    }

    public function testTraversalBudgetIsExplicit(): void
    {
        [$catalog, $target] = $this->contentTree();
        $this->expectException(\OverflowException::class);
        new StructureDestinationResolver($catalog, new \PDF\Document\Navigation\NavigationLimits(maxNodes: 3));
    }

    public function testRawKCycleIsRejectedWithoutUnboundedRecursion(): void
    {
        [$catalog] = $this->tree();
        $element = new class () extends DictionaryType {
            public ?DictionaryType $owner = null;
            public function entries(): iterable
            {
                yield new Entry(new NameType('Type'), new NameType('StructElem'));
                yield new Entry(new NameType('S'), new NameType('P'));
                yield new Entry(new NameType('P'), new IndirectReference($this->owner ?? throw new \LogicException('Fixture needs an owner.')));
                yield new Entry(new NameType('K'), new IndirectReference($this));
            }
        };
        $root = new DictionaryType(new Entry(new NameType('Type'), new NameType('StructTreeRoot')), new Entry(new NameType('K'), new IndirectReference($element)));
        $element->owner = $root;
        $catalog = $catalog->with(new Entry(new NameType('StructTreeRoot'), new IndirectReference($root)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('repeats');
        new StructureDestinationResolver($catalog);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidContentItems')]
    public function testMalformedRawContentCannotSupplyAStructureDestinationPage(\PDF\Syntax\FileStructure\BasicType $child): void
    {
        [$catalog] = $this->rawTree($child);
        $this->expectException(\InvalidArgumentException::class);
        new StructureDestinationResolver($catalog);
    }

    public function testExplicitNullDictionaryKidsAreAbsentAndUseTheFirstPageFallback(): void
    {
        foreach ([new NullType(), new IndirectReference(new NullType())] as $null) {
            [$catalog, $element, $first] = $this->rawTree($null);
            $destination = new ArrayType(new IndirectReference($element), new NameType('Fit'));
            self::assertSame($first, (new StructureDestinationResolver($catalog))->page($destination)->target());
        }
    }

    public function testNullMcrPageIsAbsentAndUsesTheContainingElementPage(): void
    {
        foreach ([new NullType(), new IndirectReference(new NullType())] as $null) {
            $child = new DictionaryType(new Entry(new NameType('Type'), new NameType('MCR')), new Entry(new NameType('MCID'), new IntegerType(0)), new Entry(new NameType('Pg'), $null));
            [$catalog, $element, $_first, $second] = $this->rawTree($child);
            $destination = new ArrayType(new IndirectReference($element), new NameType('Fit'));
            self::assertSame($second, (new StructureDestinationResolver($catalog))->page($destination)->target());
        }
    }

    /** @return array{DocumentCatalog, DictionaryType, PageObject, PageObject} */
    private function rawTree(\PDF\Syntax\FileStructure\BasicType $child): array
    {
        [$catalog, $_target, $first, $second] = $this->tree();
        $element = new class ($child, $second) extends DictionaryType {
            public ?DictionaryType $owner = null;
            public function __construct(private readonly \PDF\Syntax\FileStructure\BasicType $child, private readonly PageObject $page)
            {
                parent::__construct();
            }
            public function entries(): iterable
            {
                yield new Entry(new NameType('S'), new NameType('P'));
                yield new Entry(new NameType('P'), new IndirectReference($this->owner ?? throw new \LogicException('Fixture needs an owner.')));
                yield new Entry(new NameType('Pg'), new IndirectReference($this->page));
                yield new Entry(new NameType('K'), $this->child);
            }
        };
        $root = new DictionaryType(new Entry(new NameType('Type'), new NameType('StructTreeRoot')), new Entry(new NameType('K'), new IndirectReference($element)));
        $element->owner = $root;
        $catalog = $catalog->with(new Entry(new NameType('StructTreeRoot'), new IndirectReference($root)));
        return [$catalog, $element, $first, $second];
    }

    /** @return iterable<string, array{\PDF\Syntax\FileStructure\BasicType}> */
    public static function invalidContentItems(): iterable
    {
        yield 'negative MCID' => [new IntegerType(-1)];
        yield 'MCR without MCID' => [new DictionaryType(new Entry(new NameType('Type'), new NameType('MCR')))];
        yield 'OBJR without Obj' => [new DictionaryType(new Entry(new NameType('Type'), new NameType('OBJR')))];
    }

    /** @return array{DocumentCatalog, StructureElement, PageObject, PageObject} */
    private function tree(): array
    {
        $first = $this->page();
        $second = $this->page();
        $pages = (new PageTreeNode())->addKid($first)->addKid($second);
        $root = new StructureTreeRoot($pages);
        $target = new StructureElement(new NameType('Sect'), page: $second);
        $root->addKid($target);
        $catalog = new DocumentCatalog($pages, new Entry(new NameType('StructTreeRoot'), new IndirectReference($root)));
        return [$catalog, $target, $first, $second];
    }

    private function page(): PageObject
    {
        return new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary());
    }

    /** @return array{DocumentCatalog, StructureElement, PageObject, PageObject} */
    private function contentTree(): array
    {
        $first = $this->page();
        $content = new \PDF\Syntax\Object\StreamObject(new \PDF\Syntax\ContentStreamsResources\ContentStreams(new \PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties(new NameType('P'), new DictionaryType(new Entry(new NameType('MCID'), new IntegerType(0)))), new \PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent()));
        $second = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary(), $content, new Entry(new NameType('StructParents'), new IntegerType(0)));
        $pages = (new PageTreeNode())->addKid($first)->addKid($second);
        $root = new StructureTreeRoot($pages);
        $target = new StructureElement(new NameType('Sect'));
        $empty = new StructureElement(new NameType('P'), page: $first);
        $paragraph = new StructureElement(new NameType('P'), page: $second);
        $paragraph->addKid(new \PDF\Document\Structure\PageMarkedContent(new \PDF\Document\Structure\MarkedContentContainer($second, new IntegerType(0)), new \PDF\Document\Structure\MarkedContentIdentifier(new IntegerType(0))));
        $target->addKid($empty)->addKid((new StructureElement(new NameType('Div')))->addKid($paragraph));
        $root->addKid($target);
        $catalog = new DocumentCatalog($pages, new Entry(new NameType('StructTreeRoot'), new IndirectReference($root)));
        return [$catalog, $target, $first, $second];
    }

    private function file(DocumentCatalog $catalog, string $version = '2.0'): FileStructure
    {
        return new FileStructure(new FileHeader($version), new FileBody($catalog), new IndirectReference($catalog));
    }
}
