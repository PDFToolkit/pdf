<?php

declare(strict_types=1);

use PDF\Document\Destinations as D;
use PDF\Document\Navigation as N;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\NameDictionary;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$n = static fn (int|float $value): NumericType => new NumericType($value);
$box = new Rectangle($n(0), $n(0), $n(100), $n(200));
$page = new PageObject($box, new ResourceDictionary());
$geometry = new N\DestinationGeometry($box, new Rectangle($n(-50), $n(10), $n(80), $n(250)));
$viewport = new N\DestinationViewport($n(200), $n(200), new N\ViewPosition($n(9), $n(99), $n(3)));
$destinations = [new D\XYZ($page, zoom: $n(0)), new D\Fit($page), new D\FitH($page, $n(70)), new D\FitV($page, $n(8)), new D\FitR($page, $n(10), $n(20), $n(90), $n(120)), new D\FitB($page), new D\FitBH($page, $n(71)), new D\FitBV($page, $n(7)), new D\FitH($page), new D\FitV($page)];
$views = [];
foreach ($destinations as $destination) {
    $local = new N\LocalDestination($destination);
    $parameters = new N\ExplicitDestinationParameters($local);
    $view = $local->resolve($viewport, $geometry);
    $views[] = ['mode' => $local->mode, 'coordinates' => array_map(static fn (NumericType|NullType $coordinate): int|float|null => $coordinate instanceof NumericType ? $coordinate->value : null, $parameters->coordinates), 'view' => [$view->left->value, $view->top->value, $view->zoom->value]];
}
$labels = [];
foreach (N\LabelNumbering::cases() as $style) {
    foreach ([1, 9, 26, 27, 28, 49, 52, 53, 78, 1999, 4000] as $number) {
        $labels[] = ['style' => $style->value, 'number' => $number, 'label' => $style->format($number)];
    }
}
// Three real revisions exercise frozen builders, replacements under old aliases,
// and removing only active relationships while leaving historical xref records.
$a = new N\ArticleBead($box);
$b = new N\ArticleBead($box);
$thread = (new N\ArticleThread())->addBead($a)->addBead($b);
$drawingA = new N\PageBeads($a);
$drawingB = new N\PageBeads($b);
$sequence = (new N\PresentationSequence())->addNode(new N\NavigationNode())->addNode(new N\NavigationNode());
$first = new PageObject($box, new ResourceDictionary(), null, $drawingA->pageEntry(), $sequence->pageEntry());
$second = new PageObject($box, new ResourceDictionary(), null, $drawingB->pageEntry());
$third = new PageObject($box, new ResourceDictionary());
$pages = (new PageTreeNode())->addKid($first)->addKid($second)->addKid($third);
$drawingA->bindToPage($first);
$drawingB->bindToPage($second);
$sequence->bindToPage($first);
$tree = new NameTree(new NameTreeEntry(new LiteralString('target'), new N\LocalDestination(new D\Fit($second))));
$catalog = new DocumentCatalog($pages, (new N\ArticleThreads($thread))->catalogEntry(), new Entry(new NameType('Names'), new NameDictionary(new Entry(new NameType('Dests'), new IndirectReference($tree)))), new Entry(new NameType('OpenAction'), new IndirectReference(new N\GoToDestination(new N\NamedDestination(new LiteralString('target'))))));
$base = (new FileStructure(new FileHeader(), new FileBody($catalog)))->toRevision();
$frozen = static fn (PDF\Syntax\FileStructure\FileRevision $revision, PDF\Syntax\FileStructure\BasicType $value): DictionaryType => $revision->binding($value)->dictionary ?? throw new LogicException('Expected frozen dictionary.');
$newTree = new NameTree(new NameTreeEntry(new LiteralString('target'), new N\LocalDestination(new D\Fit($third))));
$newSecond = $frozen($base, $second)->with(new Entry(new NameType('Dur'), $n(3)));
$one = (new IncrementalUpdate($base, new FileBody(new IndirectObject($newTree, $base->binding($tree)->identifier), new IndirectObject($newSecond, $base->binding($second)->identifier))))->toRevision();
$thread->addBead(new N\ArticleBead($box)); // Not staged: must never change the frozen ring.
$newFirst = $frozen($one, $first)->without('B')->without('PresSteps');
$lastSecond = $frozen($one, $second)->without('B');
$newCatalog = $catalog->without('Threads');
$two = (new IncrementalUpdate($one, new FileBody(new IndirectObject($newFirst, $one->binding($first)->identifier), new IndirectObject($lastSecond, $one->binding($second)->identifier), new IndirectObject($newCatalog, $one->root->identifier ?? throw new LogicException('A serialized root needs its identifier.')))))->toRevision();
$revisions = ['base' => base64_encode($base->bytes), 'replacement' => base64_encode($one->bytes), 'removal' => base64_encode($two->bytes)];
// PDF32000_2008.pdf Table 152, physical p.376, and pdf_17_errata.pdf p.10:
// closed top-level items remain visible, but hidden open descendants do not require root Count.
$closedOutlines = [];
foreach (CrossReferenceFormat::cases() as $format) {
    $outlinePage = new PageObject($box, new ResourceDictionary());
    $outlinePages = (new PageTreeNode())->addKid($outlinePage);
    $hiddenOpen = (new OutlineItem(new TextString('hidden open')))
        ->addItem(new OutlineItem(new TextString('hidden grandchild'), new D\Fit($outlinePage)));
    $closedFirst = (new OutlineItem(new TextString('first closed'), new D\Fit($outlinePage), false))->addItem($hiddenOpen);
    $closedLast = (new OutlineItem(new TextString('last closed'), new D\Fit($outlinePage), false))
        ->addItem(new OutlineItem(new TextString('another hidden child'), new D\Fit($outlinePage)));
    $outline = (new OutlineDictionary())->addItem($closedFirst)->addItem($closedLast);
    $outlineCatalog = new DocumentCatalog($outlinePages, new Entry(new NameType('Outlines'), new IndirectReference($outline)));
    $closedOutlines[$format->name] = base64_encode((new FileStructure(new FileHeader(), new FileBody($outlineCatalog), crossReferenceFormat: $format))->toPdf());
}

echo json_encode(['page_box' => [0, 0, 100, 200], 'content_bounds' => [-50, 10, 80, 250], 'viewport' => [200, 200], 'current' => [9, 99, 3], 'views' => $views, 'labels' => $labels, 'revisions' => $revisions, 'closed_outlines' => $closedOutlines], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
