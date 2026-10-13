<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Destinations as D;
use PDF\Document\Navigation as N;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeChildren;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\NumberTree;
use PDF\Syntax\DataStructure\NumberTreeChildren;
use PDF\Syntax\DataStructure\NumberTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\NameDictionary;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeNavigationTest extends TestCase
{
    private function n(int|float $value): NumericType
    {
        return new NumericType($value);
    }

    private function referenceTarget(DictionaryType $dictionary, string $key): BasicType
    {
        $reference = $dictionary->get($key);
        self::assertInstanceOf(IndirectReference::class, $reference);
        return $reference->target();
    }

    private function nameValue(DictionaryType $dictionary, string $key): string
    {
        $value = $dictionary->get($key);
        self::assertInstanceOf(NameType::class, $value);
        return $value->value;
    }

    private function box(int $left = 0, int $bottom = 0, int $right = 100, int $top = 200): Rectangle
    {
        return new Rectangle($this->n($left), $this->n($bottom), $this->n($right), $this->n($top));
    }

    private function page(Entry ...$entries): PageObject
    {
        return new PageObject($this->box(), new ResourceDictionary(), null, ...$entries);
    }

    private function file(DocumentCatalog $catalog, string $version = '1.7'): FileStructure
    {
        return new FileStructure(new FileHeader($version), new FileBody($catalog), new IndirectReference($catalog));
    }

    private function frozen(FileRevision $revision, BasicType $value): DictionaryType
    {
        return $revision->binding($value)->dictionary ?? throw new \LogicException('The test object must have a dictionary snapshot.');
    }

    /** @return array{DocumentCatalog, PageObject, N\ArticleThread} */
    private function oneArticle(): array
    {
        $bead = new N\ArticleBead($this->box());
        $thread = (new N\ArticleThread())->addBead($bead);
        $drawing = new N\PageBeads($bead);
        $page = $this->page($drawing->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $drawing->bindToPage($page);
        return [new DocumentCatalog($pages, (new N\ArticleThreads($thread))->catalogEntry()), $page, $thread];
    }

    public function testIncrementalArticleUsesFrozenRingRatherThanMutatedBuilder(): void
    {
        [$catalog, $page, $thread] = $this->oneArticle();
        $base = $this->file($catalog)->toRevision();
        $thread->addBead(new N\ArticleBead($this->box()));
        $next = (new IncrementalUpdate($base, new FileBody()))->toRevision();
        self::assertSame($base->binding($thread)->dictionary, $next->binding($thread)->dictionary);
        self::assertStringNotContainsString('/Type /Bead', substr($next->bytes, strlen($base->bytes)));
    }

    public function testIncrementalBeadPageAliasUsesActualPdfIdentity(): void
    {
        [$catalog, $page, $thread] = $this->oneArticle();
        $base = $this->file($catalog)->toRevision();
        $replacement = $this->frozen($base, $page)->with(new Entry(new NameType('Dur'), $this->n(2)));
        $next = (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($page)->identifier))))->toRevision();
        self::assertSame($replacement, $next->reference($page)->target());
        self::assertStringContainsString('/Dur 2', substr($next->bytes, strlen($base->bytes)));
    }

    public function testIncrementalArticleRemovalLeavesUnreachableOldObjectsValid(): void
    {
        [$catalog, $page, $thread] = $this->oneArticle();
        $base = $this->file($catalog)->toRevision();
        $replacement = $this->frozen($base, $page)->without('B');
        $nextCatalog = $catalog->without('Threads');
        $next = (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($page)->identifier), new IndirectObject($nextCatalog, $base->binding($catalog)->identifier))))->toRevision();
        self::assertSame($base->binding($thread)->identifier, $next->binding($thread)->identifier);
        self::assertNull($next->binding($nextCatalog)->dictionary?->get('Threads'));
    }

    public function testIncrementalRemovingThreadsWithoutPageBeadsIsRejected(): void
    {
        [$catalog, $page, $thread] = $this->oneArticle();
        $base = $this->file($catalog)->toRevision();
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($catalog->without('Threads'), $base->binding($catalog)->identifier))))->toRevision();
    }

    public function testIncrementalNamedLookupUsesReplacedTreeBehindHistoricalAlias(): void
    {
        $first = $this->page();
        $last = $this->page();
        $tree = new NameTree(new NameTreeEntry(new LiteralString('target'), new N\LocalDestination(new D\Fit($first))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($first)->addKid($last), new Entry(new NameType('Names'), new NameDictionary(new Entry(new NameType('Dests'), new IndirectReference($tree)))), new Entry(new NameType('OpenAction'), new IndirectReference(new N\GoToDestination(new N\NamedDestination(new LiteralString('target'))))));
        $base = $this->file($catalog)->toRevision();
        $replacement = new NameTree(new NameTreeEntry(new LiteralString('target'), new N\LocalDestination(new D\Fit($last))));
        $next = (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($tree)->identifier))))->toRevision();
        self::assertSame($replacement, $next->reference($tree)->target());
        self::assertStringContainsString($base->binding($last)->identifier . ' R /Fit', substr($next->bytes, strlen($base->bytes)));
    }

    public function testIncrementalNamedLookupRejectsDeletedHistoricalActionTarget(): void
    {
        $page = $this->page();
        $tree = new NameTree(new NameTreeEntry(new LiteralString('target'), new N\LocalDestination(new D\Fit($page))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Names'), new NameDictionary(new Entry(new NameType('Dests'), new IndirectReference($tree)))), new Entry(new NameType('OpenAction'), new IndirectReference(new N\GoToDestination(new N\NamedDestination(new LiteralString('target'))))));
        $base = $this->file($catalog)->toRevision();
        $replacement = new NameTree(new NameTreeEntry(new LiteralString('another'), new N\LocalDestination(new D\Fit($page))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absent');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($tree)->identifier))))->toRevision();
    }

    public function testIncrementalPresentationRejectsBrokenEffectiveNextPrev(): void
    {
        $first = new N\NavigationNode();
        $last = new N\NavigationNode();
        $sequence = (new N\PresentationSequence())->addNode($first)->addNode($last);
        $page = $this->page($sequence->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $sequence->bindToPage($page);
        $base = $this->file(new DocumentCatalog($pages))->toRevision();
        $replacement = $this->frozen($base, $last)->without('Prev');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reciprocal');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($last)->identifier))))->toRevision();
    }

    public function testIncrementalPresentationRemovalDoesNotRebindHistoricalNodes(): void
    {
        $node = new N\NavigationNode();
        $sequence = (new N\PresentationSequence())->addNode($node);
        $page = $this->page($sequence->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $sequence->bindToPage($page);
        $base = $this->file(new DocumentCatalog($pages))->toRevision();
        $replacement = $this->frozen($base, $page)->without('PresSteps');
        $next = (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($page)->identifier))))->toRevision();
        self::assertSame($base->binding($node)->identifier, $next->binding($node)->identifier);
    }

    #[DataProvider('shrunkenPageCases')]
    public function testIncrementalActualPageRemovalInvalidatesFrozenNavigation(bool $labels): void
    {
        $first = $this->page();
        $last = $this->page();
        $pages = (new PageTreeNode())->addKid($first)->addKid($last);
        $navigation = $labels
            ? (new N\PageLabels(new N\LabelRange(new IntegerType(0), new N\PageLabel()), new N\LabelRange(new IntegerType(1), new N\PageLabel())))->catalogEntry()
            : new Entry(new NameType('OpenAction'), new N\LocalDestination(new D\Fit($last)));
        $base = $this->file(new DocumentCatalog($pages, $navigation))->toRevision();
        $replacement = $this->frozen($base, $pages)->with(new Entry(new NameType('Kids'), new ArrayType(new IndirectReference($first))))->with(new Entry(new NameType('Count'), new IntegerType(1)));
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($pages)->identifier))))->toRevision();
    }

    /** @return iterable<string, array{bool}> */
    public static function shrunkenPageCases(): iterable
    {
        yield 'label range' => [true];
        yield 'direct destination' => [false];
    }

    public function testGenericTransitionStillEnforcesSelectedFeatureVersion(): void
    {
        $transition = new DictionaryType(new Entry(new NameType('S'), new NameType('Fade')));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page(new Entry(new NameType('Trans'), $transition))));
        $this->expectException(\LogicException::class);
        $this->file($catalog, '1.4')->toPdf();
    }

    public function testTerminalPresentationActionStillRequiresActionDictionary(): void
    {
        $node = new DictionaryType(new Entry(new NameType('NA'), new IntegerType(42)));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page(new Entry(new NameType('PresSteps'), $node))));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testBranchedLabelTreeUsesDerivedChildLimits(): void
    {
        $tree = new NumberTree(new NumberTreeChildren(new NumberTree(new NumberTreeEntry(new IntegerType(1), new N\PageLabel(N\LabelNumbering::UpperLetters))), new NumberTree(new NumberTreeEntry(new IntegerType(0), new N\PageLabel(N\LabelNumbering::LowerRoman)))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page())->addKid($this->page()), new Entry(new NameType('PageLabels'), $tree));
        self::assertStringContainsString('/Limits [0 0]', $this->file($catalog)->toPdf());
    }

    #[DataProvider('badLabelTreeCases')]
    public function testWriterRejectsInvalidGenericLabelTree(string $problem): void
    {
        $first = new DictionaryType(new Entry(new NameType('Nums'), new ArrayType(new IntegerType(0), new N\PageLabel())), new Entry(new NameType('Limits'), new ArrayType(new IntegerType(0), new IntegerType(0))));
        $second = new DictionaryType(new Entry(new NameType('Nums'), new ArrayType(new IntegerType(1), new N\PageLabel())), new Entry(new NameType('Limits'), new ArrayType(new IntegerType(1), new IntegerType(1))));
        if ($problem === 'missing limits') {
            $second = $second->without('Limits');
        } elseif ($problem === 'inaccurate limits') {
            $second = $second->with(new Entry(new NameType('Limits'), new ArrayType(new IntegerType(0), new IntegerType(1))));
        } elseif ($problem === 'invalid prefix') {
            $second = $second->with(new Entry(new NameType('Nums'), new ArrayType(new IntegerType(1), new DictionaryType(new Entry(new NameType('P'), new IntegerType(42))))));
        }
        $children = $problem === 'unordered children' ? [$second, $first] : [$first, $second];
        $tree = new DictionaryType(new Entry(new NameType('Kids'), new ArrayType(...array_map(static fn (DictionaryType $child): IndirectReference => new IndirectReference($child), $children))));
        if ($problem === 'root limits') {
            $tree = $tree->with(new Entry(new NameType('Limits'), new ArrayType(new IntegerType(0), new IntegerType(1))));
        }
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page())->addKid($this->page()), new Entry(new NameType('PageLabels'), $tree));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    /** @return iterable<string, array{string}> */
    public static function badLabelTreeCases(): iterable
    {
        foreach (['missing limits', 'inaccurate limits', 'unordered children', 'invalid prefix', 'root limits'] as $case) {
            yield $case => [$case];
        }
    }

    public function testGenericTransitionEnforcesStyleDependentParameters(): void
    {
        $transition = new DictionaryType(new Entry(new NameType('S'), new NameType('Box')), new Entry(new NameType('Dm'), new NameType('H')));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page(new Entry(new NameType('Trans'), $transition))));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testMetadataTitleParentDestDoesNotBecomeAnOutlineDeclaration(): void
    {
        $metadata = new DictionaryType(new Entry(new NameType('Title'), new LiteralString('private')), new Entry(new NameType('Parent'), new DictionaryType()), new Entry(new NameType('Dest'), new IntegerType(42)), new Entry(new NameType('S'), new NameType('GoTo')), new Entry(new NameType('D'), new IntegerType(42)));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page()), new Entry(new NameType('Private'), $metadata));
        self::assertStringContainsString('/Dest 42', $this->file($catalog)->toPdf());
    }

    public function testNativeGoToComposesFollowingActionArray(): void
    {
        $page = $this->page();
        $following = new DictionaryType(new Entry(new NameType('S'), new NameType('Named')), new Entry(new NameType('N'), new NameType('FirstPage')));
        $action = new N\GoToDestination(new N\LocalDestination(new D\Fit($page)), new ArrayType(new IndirectReference($following)));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('OpenAction'), $action));
        self::assertStringContainsString('/Next [', $this->file($catalog, '1.2')->toPdf());
        $this->expectException(\LogicException::class);
        $this->file($catalog, '1.1')->toPdf();
    }

    public function testUntypedGoToDictionaryIsRecognizedFromActualActionSlot(): void
    {
        $action = new DictionaryType(new Entry(new NameType('S'), new NameType('GoTo')));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page()), new Entry(new NameType('OpenAction'), $action));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('D destination');
        $this->file($catalog)->toPdf();
    }

    #[DataProvider('versionUpgradeCases')]
    public function testIncrementalNonfirstBeadThreadEntryUsesEffectiveVersion(bool $upgrade): void
    {
        $a = new N\ArticleBead($this->box());
        $b = new N\ArticleBead($this->box());
        $thread = (new N\ArticleThread())->addBead($a)->addBead($b);
        $drawing = new N\PageBeads($a, $b);
        $page = $this->page($drawing->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $drawing->bindToPage($page);
        $catalog = new DocumentCatalog($pages, (new N\ArticleThreads($thread))->catalogEntry());
        $base = $this->file($catalog, '1.1')->toRevision();
        $bead = $this->frozen($base, $b)->with(new Entry(new NameType('T'), new IndirectReference($thread)));
        $body = new FileBody(new IndirectObject($bead, $base->binding($b)->identifier));
        if ($upgrade) {
            $nextCatalog = $catalog->with(new Entry(new NameType('Version'), new NameType('1.4')));
            $body->addObject(new IndirectObject($nextCatalog, $base->binding($catalog)->identifier));
            self::assertStringContainsString('/Version /1.4', (new IncrementalUpdate($base, $body))->toPdf());
        } else {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Nonfirst bead T');
            (new IncrementalUpdate($base, $body))->toPdf();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function versionUpgradeCases(): iterable
    {
        yield 'header only' => [false];
        yield 'catalogue version' => [true];
    }

    /** @return iterable<string, array{N\LabelNumbering, int, string}> */
    public static function numberingCases(): iterable
    {
        yield 'decimal' => [N\LabelNumbering::Decimal, 120, '120'];
        yield 'roman subtraction' => [N\LabelNumbering::UpperRoman, 49, 'XLIX'];
        yield 'roman lowercase' => [N\LabelNumbering::LowerRoman, 1999, 'mcmxcix'];
        yield 'roman large' => [N\LabelNumbering::UpperRoman, 4000, 'MMMM'];
        yield 'first letter' => [N\LabelNumbering::UpperLetters, 1, 'A'];
        yield 'letter26' => [N\LabelNumbering::UpperLetters, 26, 'Z'];
        yield 'letter27' => [N\LabelNumbering::UpperLetters, 27, 'AA'];
        yield 'letter28' => [N\LabelNumbering::UpperLetters, 28, 'BB'];
        yield 'letter52' => [N\LabelNumbering::UpperLetters, 52, 'ZZ'];
        yield 'letter53' => [N\LabelNumbering::LowerLetters, 53, 'aaa'];
        yield 'letter78' => [N\LabelNumbering::LowerLetters, 78, 'zzz'];
    }

    #[DataProvider('numberingCases')]
    public function testSourceNumberingStyles(N\LabelNumbering $style, int $number, string $expected): void
    {
        self::assertSame($expected, $style->format($number));
    }

    public function testPrefixOnlyLabelsDoNotInventAStyleOrComputeIgnoredCounter(): void
    {
        $label = new N\PageLabel(prefix: new TextString('Sommaire'), start: new IntegerType(PHP_INT_MAX));
        self::assertSame('Sommaire', $label->label(new IntegerType(PHP_INT_MAX))->text);
        self::assertSame('', (new N\PageLabel())->label(new IntegerType(100))->text);
        self::assertNull($label->get('S'));
    }

    public function testRangesAreSortedAndBoundToActualPages(): void
    {
        $labels = new N\PageLabels(new N\LabelRange(new IntegerType(2), new N\PageLabel(N\LabelNumbering::Decimal, new TextString('A-'), new IntegerType(8))), new N\LabelRange(new IntegerType(0), new N\PageLabel(N\LabelNumbering::LowerRoman)));
        $pages = (new PageTreeNode())->addKid($this->page())->addKid($this->page())->addKid($this->page());
        $catalog = new DocumentCatalog($pages, $labels->catalogEntry());
        self::assertSame(['i', 'ii', 'A-8'], array_map(static fn (TextString $label): string => $label->text, $labels->labelsFor($catalog)));
        self::assertStringContainsString('/Nums [0', $this->file($catalog)->toPdf());
    }

    public function testPageZeroRangeIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N\PageLabels(new N\LabelRange(new IntegerType(1), new N\PageLabel()));
    }

    public function testDuplicateRangesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N\PageLabels(new N\LabelRange(new IntegerType(0), new N\PageLabel()), new N\LabelRange(new IntegerType(0), new N\PageLabel()));
    }

    public function testWriterRejectsRangeBeyondLastPage(): void
    {
        $labels = new N\PageLabels(new N\LabelRange(new IntegerType(0), new N\PageLabel()), new N\LabelRange(new IntegerType(1), new N\PageLabel()));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page()), $labels->catalogEntry());
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testWriterRequiresLabelVersion13(): void
    {
        $labels = new N\PageLabels(new N\LabelRange(new IntegerType(0), new N\PageLabel()));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page()), $labels->catalogEntry());
        $this->expectException(\LogicException::class);
        $this->file($catalog, '1.2')->toPdf();
    }

    public function testSequentialNumberOverflowIsExplicit(): void
    {
        $this->expectException(\OutOfRangeException::class);
        (new N\PageLabel(N\LabelNumbering::Decimal, start: new IntegerType(PHP_INT_MAX)))->label(new IntegerType(1));
    }

    public function testAlphabeticOutputBudgetIsCheckedBeforeAllocating(): void
    {
        $this->expectException(\LengthException::class);
        N\LabelNumbering::UpperLetters->format(PHP_INT_MAX, new N\NavigationLimits(maxLabelBytes: 10));
    }

    public function testLabelStartZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N\PageLabel(start: new IntegerType(0));
    }

    public function testAllEightDestinationModesResolveExplicitGeometry(): void
    {
        $page = $this->page();
        $viewport = new N\DestinationViewport($this->n(200), $this->n(200), new N\ViewPosition($this->n(9), $this->n(99), $this->n(3)));
        $geometry = new N\DestinationGeometry($this->box(), $this->box(-50, 10, 80, 250));
        $cases = [
            [new D\XYZ($page, zoom: $this->n(0)), 9, 99, 3],
            [new D\Fit($page), -50, 200, 1],
            [new D\FitH($page, $this->n(70)), 0, 70, 2],
            [new D\FitV($page, $this->n(8)), 8, 200, 1],
            [new D\FitR($page, $this->n(10), $this->n(20), $this->n(90), $this->n(120)), 0, 120, 2],
            [new D\FitB($page), -55, 200, 200 / 190],
            [new D\FitBH($page, $this->n(71)), 0, 71, 2.5],
            [new D\FitBV($page, $this->n(7)), 7, 200, 200 / 190],
        ];
        foreach ($cases as [$destination, $left, $top, $zoom]) {
            $result = (new N\LocalDestination($destination))->resolve($viewport, $geometry);
            self::assertEqualsWithDelta($left, $result->left->value, 1e-10);
            self::assertEqualsWithDelta($top, $result->top->value, 1e-10);
            self::assertEqualsWithDelta($zoom, $result->zoom->value, 1e-10);
        }
    }

    public function testNullFitCoordinatesRetainCurrentPosition(): void
    {
        $page = $this->page();
        $current = new N\ViewPosition($this->n(7), $this->n(66), $this->n(2));
        $viewport = new N\DestinationViewport($this->n(200), $this->n(200), $current);
        self::assertSame(66, (new N\LocalDestination(new D\FitH($page)))->resolve($viewport, new N\DestinationGeometry($this->box()))->top->value);
        self::assertSame(7, (new N\LocalDestination(new D\FitV($page)))->resolve($viewport, new N\DestinationGeometry($this->box()))->left->value);
    }

    public function testNullCoordinateNeedsExplicitCurrentView(): void
    {
        $this->expectException(\LogicException::class);
        (new N\LocalDestination(new D\XYZ($this->page())))->resolve(new N\DestinationViewport($this->n(100), $this->n(100)), new N\DestinationGeometry($this->box()));
    }

    public function testFitBoundingBoxDoesNotInferContent(): void
    {
        $this->expectException(\LogicException::class);
        (new N\LocalDestination(new D\FitB($this->page())))->resolve(new N\DestinationViewport($this->n(100), $this->n(100)), new N\DestinationGeometry($this->box()));
    }

    public function testReversedFitRectangleIsNormalizedForFitting(): void
    {
        $view = (new N\LocalDestination(new D\FitR($this->page(), $this->n(90), $this->n(120), $this->n(10), $this->n(20))))->resolve(new N\DestinationViewport($this->n(200), $this->n(200)), new N\DestinationGeometry($this->box()));
        self::assertSame(2, $view->zoom->value);
        self::assertSame(0, $view->left->value);
    }

    public function testZeroAreaFitRectangleHasNoDefinedMagnification(): void
    {
        $this->expectException(\OutOfRangeException::class);
        (new N\LocalDestination(new D\FitR($this->page(), $this->n(0), $this->n(0), $this->n(0), $this->n(10))))->resolve(new N\DestinationViewport($this->n(100), $this->n(100)), new N\DestinationGeometry($this->box()));
    }

    public function testWriterRejectsForeignExplicitDestination(): void
    {
        $foreign = $this->page();
        (new PageTreeNode())->addKid($foreign);
        $destination = new N\LocalDestination(new D\Fit($foreign));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page()), new Entry(new NameType('OpenAction'), $destination));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testLegacyAndStringNamespacesCoexistWithoutAliasingTheirKeys(): void
    {
        $one = $this->page();
        $two = $this->page();
        $first = new N\LocalDestination(new D\Fit($one));
        $second = new N\LocalDestination(new D\Fit($two));
        $legacy = new N\LegacyDestinations(new N\NamedDestinationEntry(new NameType('chapter'), $first));
        $names = new N\NamedDestinations(new N\NamedDestinationEntry(new LiteralString('chapter'), new N\DestinationDictionary($second)));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($one)->addKid($two), $legacy->catalogEntry(), $names->catalogEntry(), new Entry(new NameType('OpenAction'), new N\GoToDestination(new N\NamedDestination(new LiteralString('chapter')))));
        self::assertSame($first, (new N\NamedDestination(new NameType('chapter')))->resolve($catalog));
        self::assertSame($second, (new N\NamedDestination(new LiteralString('chapter')))->resolve($catalog));
        self::assertStringContainsString('/D (chapter)', $this->file($catalog)->toPdf());
    }

    public function testNamedLookupWalksRealNameTreeBranchesAndBinaryKeys(): void
    {
        $page = $this->page();
        $destination = new N\LocalDestination(new D\Fit($page));
        $tree = new NameTree(new NameTreeChildren(new NameTree(new NameTreeEntry(new LiteralString("a\0"), $destination)), new NameTree(new NameTreeEntry(new LiteralString('z'), $destination))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Names'), new NameDictionary(new Entry(new NameType('Dests'), $tree))));
        self::assertSame($destination, (new N\NamedDestination(new LiteralString("a\0")))->resolve($catalog));
    }

    public function testMissingNamedDestinationIsRejectedByActualWriter(): void
    {
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page()), new Entry(new NameType('OpenAction'), new N\GoToDestination(new N\NamedDestination(new LiteralString('absent')))));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testNamedLookupRejectsMalformedExplicitValue(): void
    {
        $page = $this->page();
        $tree = new NameTree(new NameTreeEntry(new LiteralString('bad'), new ArrayType(new IndirectReference($page), new NameType('FitR'), new NullType())));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Names'), new NameDictionary(new Entry(new NameType('Dests'), $tree))));
        $this->expectException(\InvalidArgumentException::class);
        (new N\NamedDestination(new LiteralString('bad')))->resolve($catalog);
    }

    public function testWriterChecksNamedDestinationVersion(): void
    {
        $page = $this->page();
        $names = new N\NamedDestinations(new N\NamedDestinationEntry(new LiteralString('name'), new N\LocalDestination(new D\Fit($page))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $names->catalogEntry(), new Entry(new NameType('OpenAction'), new N\GoToDestination(new N\NamedDestination(new LiteralString('name')))));
        $this->expectException(\LogicException::class);
        $this->file($catalog, '1.1')->toPdf();
    }

    public function testOneBeadRingAndRequiredIndirectCatalogueArray(): void
    {
        $bead = new N\ArticleBead($this->box());
        $thread = (new N\ArticleThread())->addBead($bead);
        $beads = new N\PageBeads($bead);
        $page = $this->page($beads->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $beads->bindToPage($page);
        $catalog = new DocumentCatalog($pages, (new N\ArticleThreads($thread))->catalogEntry());
        self::assertSame($bead, $this->referenceTarget($bead, 'N'));
        self::assertSame($bead, $this->referenceTarget($bead, 'V'));
        self::assertSame($thread, $this->referenceTarget($bead, 'T'));
        self::assertSame($page, $this->referenceTarget($bead, 'P'));
        self::assertStringContainsString('/Type /Thread', $this->file($catalog, '1.1')->toPdf());
    }

    public function testArticleRingAndPageDrawingOrderAreIndependent(): void
    {
        $a = new N\ArticleBead($this->box());
        $b = new N\ArticleBead($this->box());
        $thread = (new N\ArticleThread())->addBead($a)->addBead($b);
        $beads = new N\PageBeads($b, $a);
        $page = $this->page($beads->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $beads->bindToPage($page);
        $catalog = new DocumentCatalog($pages, (new N\ArticleThreads($thread))->catalogEntry());
        $drawingOrder = $page->get('B');
        self::assertInstanceOf(ArrayType::class, $drawingOrder);
        self::assertInstanceOf(IndirectReference::class, $drawingOrder->items[1]);
        self::assertSame($a, $drawingOrder->items[1]->target());
        self::assertSame($b, $this->referenceTarget($a, 'N'));
        self::assertSame($a, $this->referenceTarget($b, 'N'));
        self::assertNull($b->get('T'));
        self::assertStringContainsString('/Type /Bead', $this->file($catalog)->toPdf());
    }

    public function testBeadCannotBelongToTwoThreads(): void
    {
        $bead = new N\ArticleBead($this->box());
        (new N\ArticleThread())->addBead($bead);
        $this->expectException(\InvalidArgumentException::class);
        (new N\ArticleThread())->addBead($bead);
    }

    public function testUnregisteredArticleIsRejectedByWriter(): void
    {
        $bead = new N\ArticleBead($this->box());
        (new N\ArticleThread())->addBead($bead);
        $beads = new N\PageBeads($bead);
        $page = $this->page($beads->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $beads->bindToPage($page);
        $this->expectException(\InvalidArgumentException::class);
        $this->file(new DocumentCatalog($pages))->toPdf();
    }

    public function testWriterRejectsBeadInTwoPages(): void
    {
        $bead = new N\ArticleBead($this->box());
        $thread = (new N\ArticleThread())->addBead($bead);
        $beads = new N\PageBeads($bead);
        $page = $this->page($beads->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $beads->bindToPage($page);
        $other = $this->page(new Entry(new NameType('B'), new ArrayType(new IndirectReference($bead))));
        $catalog = new DocumentCatalog($pages->addKid($other), (new N\ArticleThreads($thread))->catalogEntry());
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testWriterRejectsDuplicateBeadMembershipEvenOnSamePage(): void
    {
        $bead = new N\ArticleBead($this->box());
        $thread = (new N\ArticleThread())->addBead($bead);
        $page = $this->page(new Entry(new NameType('B'), new ArrayType(new IndirectReference($bead), new IndirectReference($bead))));
        $pages = (new PageTreeNode())->addKid($page);
        $bead->bindToPage($page);
        $catalog = new DocumentCatalog($pages, (new N\ArticleThreads($thread))->catalogEntry());
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testNonfirstThreadEntryNeedsPdf12(): void
    {
        $a = new N\ArticleBead($this->box());
        $b = new N\ArticleBead($this->box(), includeThread: true);
        $thread = (new N\ArticleThread())->addBead($a)->addBead($b);
        $beads = new N\PageBeads($a, $b);
        $page = $this->page($beads->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $beads->bindToPage($page);
        $catalog = new DocumentCatalog($pages, (new N\ArticleThreads($thread))->catalogEntry());
        $this->expectException(\LogicException::class);
        $this->file($catalog, '1.1')->toPdf();
    }

    public function testTransitionsPreserveStyleSpecificParametersAndDurations(): void
    {
        foreach (N\TransitionStyle::cases() as $style) {
            $transition = new N\Transition($style);
            self::assertSame($style->value, $this->nameValue($transition, 'S'));
        }
        $split = new N\Transition(N\TransitionStyle::Split, new N\PresentationDuration($this->n(3.5)), N\TransitionDimension::Vertical, N\TransitionMotion::Outward);
        $presentation = new N\PagePresentation(new N\PresentationDuration($this->n(5)), $split);
        $interval = $presentation->automaticInterval();
        self::assertInstanceOf(N\PresentationDuration::class, $interval);
        self::assertSame(8.5, $interval->value);
        self::assertNull((new N\PagePresentation(transition: $split))->automaticInterval());
        self::assertSame(0, (new N\Transition(duration: new N\PresentationDuration($this->n(10))))->effectDuration()->value);
    }

    public function testDirection180IsWipeOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N\Transition(N\TransitionStyle::Fly, direction: N\TransitionDirection::RightToLeft);
    }

    public function testCloudDiagonalDirectionIsGlitterOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N\Transition(N\TransitionStyle::Wipe, direction: N\TransitionDirection::TopLeftToBottomRight);
    }

    public function testFlyInPlaceNeedsNonunitScaleAndSupportsZeroScale(): void
    {
        $fly = new N\Transition(N\TransitionStyle::Fly, direction: N\TransitionDirection::None, scale: $this->n(0), rectangular: new BooleanType(true));
        self::assertSame('None', $this->nameValue($fly, 'Di'));
        $scale = $fly->get('SS');
        self::assertInstanceOf(NumericType::class, $scale);
        self::assertSame(0, $scale->value);
        $this->expectException(\InvalidArgumentException::class);
        new N\Transition(N\TransitionStyle::Fly, direction: N\TransitionDirection::None, scale: $this->n(1));
    }

    public function testDimensionDoesNotApplyToBoxTransition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N\Transition(N\TransitionStyle::Box, dimension: N\TransitionDimension::Horizontal);
    }

    public function testNegativePresentationTimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N\PresentationDuration($this->n(-1));
    }

    public function testSelectedTransitionStyleVersionIsEnforcedByWriter(): void
    {
        $presentation = new N\PagePresentation(transition: new N\Transition(N\TransitionStyle::Fade));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($this->page(...$presentation->entries())));
        $this->expectException(\LogicException::class);
        $this->file($catalog, '1.4')->toPdf();
    }

    public function testPresentationListDerivesReciprocalLinksAndEntryRequests(): void
    {
        $first = new N\NavigationNode(duration: new N\PresentationDuration($this->n(0)));
        $primary = new N\NavigationNode();
        $last = new N\NavigationNode();
        $sequence = (new N\PresentationSequence())->addNode($first)->addNode($primary)->addNode($last);
        $page = $this->page($sequence->pageEntry($primary));
        $pages = (new PageTreeNode())->addKid($page);
        $sequence->bindToPage($page);
        $catalog = new DocumentCatalog($pages);
        self::assertNull($first->previous());
        self::assertNull($last->next());
        self::assertSame($first, $this->referenceTarget($primary, 'Prev'));
        self::assertSame($last, $this->referenceTarget($primary, 'Next'));
        self::assertSame($last, $sequence->enter(N\NavigationDirection::RandomAccess)->next);
        self::assertSame($first, $sequence->enter(N\NavigationDirection::Backward)->next);
        self::assertStringContainsString('/PresSteps', $this->file($catalog)->toPdf());
    }

    public function testPresentationNodesCannotBeSharedBetweenPages(): void
    {
        $node = new N\NavigationNode();
        (new N\PresentationSequence())->addNode($node);
        $this->expectException(\InvalidArgumentException::class);
        (new N\PresentationSequence())->addNode($node);
    }

    public function testWriterRejectsPresentationPrimaryReusedOnAnotherPage(): void
    {
        $sequence = (new N\PresentationSequence())->addNode(new N\NavigationNode());
        $page = $this->page($sequence->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $sequence->bindToPage($page);
        $other = $this->page($sequence->pageEntry());
        $catalog = new DocumentCatalog($pages->addKid($other));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($catalog)->toPdf();
    }

    public function testKnownForwardPageExitMustNotHaveAnotherPresentationNode(): void
    {
        $other = $this->page();
        $action = new N\GoToDestination(new N\LocalDestination(new D\Fit($other)));
        $sequence = (new N\PresentationSequence())->addNode(new N\NavigationNode($action))->addNode(new N\NavigationNode());
        $page = $this->page($sequence->pageEntry());
        $pages = (new PageTreeNode())->addKid($page)->addKid($other);
        $sequence->bindToPage($page);
        $catalog = new DocumentCatalog($pages);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('leaving the page');
        $this->file($catalog)->toPdf();
    }

    public function testPageExitActionIsAllowedAtTerminalPresentationNode(): void
    {
        $other = $this->page();
        $action = new N\GoToDestination(new N\LocalDestination(new D\Fit($other)));
        $first = new N\NavigationNode();
        $last = new N\NavigationNode($action);
        $sequence = (new N\PresentationSequence())->addNode($first)->addNode($last);
        $page = $this->page($sequence->pageEntry());
        $pages = (new PageTreeNode())->addKid($page)->addKid($other);
        $sequence->bindToPage($page);
        $catalog = new DocumentCatalog($pages);
        self::assertSame($action, $last->request(N\NavigationDirection::Forward)->action);
        self::assertNull($last->request(N\NavigationDirection::Forward)->next);
        self::assertStringContainsString('/NA', $this->file($catalog)->toPdf());
    }

    public function testActionNextIsInspectedWithoutBanningSharedOrCyclicActions(): void
    {
        $page = $this->page();
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $action = new class () extends DictionaryType {
            public function __construct()
            {
                parent::__construct(new Entry(new NameType('S'), new NameType('Named')), new Entry(new NameType('N'), new NameType('FirstPage')));
            }
            public function entries(): iterable
            {
                yield from parent::entries();
                yield new Entry(new NameType('Next'), new IndirectReference($this));
            }
        };
        self::assertFalse((new N\NavigationActionEffects())->leavesPage($action, $page, $catalog));
        self::assertStringContainsString('/Next', $this->file($catalog->with(new Entry(new NameType('OpenAction'), new IndirectReference($action))))->toPdf());
    }

    public function testPresentationVersion15CanBeProvidedByCatalogue(): void
    {
        $sequence = (new N\PresentationSequence())->addNode(new N\NavigationNode());
        $page = $this->page($sequence->pageEntry());
        $pages = (new PageTreeNode())->addKid($page);
        $sequence->bindToPage($page);
        $catalog = new DocumentCatalog($pages, new Entry(new NameType('Version'), new NameType('1.5')));
        self::assertStringContainsString('/NavNode', $this->file($catalog, '1.4')->toPdf());
    }
}
