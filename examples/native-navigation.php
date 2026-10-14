<?php

declare(strict_types=1);

use PDF\Document\Destinations\Fit;
use PDF\Document\Destinations\FitR;
use PDF\Document\Destinations\XYZ;
use PDF\Document\DocumentInformation;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Navigation as N;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

$n = static fn (int|float $value): NumericType => new NumericType($value);
$rectangle = static fn (int $left, int $bottom, int $right, int $top): Rectangle => new Rectangle($n($left), $n($bottom), $n($right), $n($top));
$pageBox = $rectangle(0, 0, 612, 792);
$resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))))));
/** @return list<Operator> */
$text = static fn (string $value, int $x, int $y, int $size = 12): array => [new Op\SetNonstrokingRGBColor($n(0.12), $n(0.18), $n(0.24)), new Op\BeginTextObject(), new Op\SetTextFontAndSize(new NameType('F1'), $n($size)), new Op\MoveTextPosition($n($x), $n($y)), new Op\ShowText(new LiteralString($value)), new Op\EndTextObject()];
/** @return list<Operator> */
$region = static fn (int $x, int $y, int $width, int $height): array => [new Op\SetNonstrokingRGBColor($n(0.88), $n(0.95), $n(0.93)), new Op\AppendRectangle($n($x), $n($y), $n($width), $n($height)), new Op\FillPathNonzero()];

// Labels are NumberTree ranges, distinct from zero-based indices.
// The second page starts at 27: A repeats the same letter, giving "AA".
$labels = new N\PageLabels(
    new N\LabelRange(new IntegerType(0), new N\PageLabel(N\LabelNumbering::LowerRoman)),
    new N\LabelRange(new IntegerType(1), new N\PageLabel(N\LabelNumbering::UpperLetters, start: new IntegerType(27))),
    new N\LabelRange(new IntegerType(2), new N\PageLabel(N\LabelNumbering::Decimal, new TextString('A-'), new IntegerType(8))),
);

// The article goes from page0 to page2, then returns to page1.
// N/V are derived from this order and automatically close the ring.
$a = new N\ArticleBead($rectangle(42, 440, 570, 624));
$b = new N\ArticleBead($rectangle(42, 420, 570, 620), includeThread: true);
$c = new N\ArticleBead($rectangle(42, 250, 570, 422));
$article = (new N\ArticleThread(new DocumentInformation(new Entry(new NameType('Title'), new TextString('Article objet')))))->addBead($a)->addBead($b)->addBead($c);
$note = new N\ArticleBead($rectangle(42, 460, 570, 630));
$secondArticle = (new N\ArticleThread(new DocumentInformation(new Entry(new NameType('Title'), new TextString('Note independante')))))->addBead($note);
$firstBeads = new N\PageBeads($a);
$middleBeads = new N\PageBeads($note, $c);
$lastBeads = new N\PageBeads($b);

// Page B gives drawing order, here Note then article continuation.
// It can therefore differ from the order of each article ring.
$lastPresentation = new N\PagePresentation(transition: new N\Transition(N\TransitionStyle::Dissolve));
$last = new PageObject($pageBox, $resources, new StreamObject(new ContentStreams(
    ...$text('Articles et destinations', 42, 742, 25),
    ...$text('Label A-8 / index 2', 42, 708, 15),
    ...$region(42, 420, 528, 200),
    ...$text('Article : deuxieme bead', 61, 585, 20),
    ...$text('La lecture passe ensuite a la page AA.', 61, 550),
    ...$text('La destination details cadre cette region avec FitR.', 61, 523),
    ...$text('Les signets utilisent soit un nom, soit une chaine.', 42, 358),
    ...$text('Ces deux espaces de destinations peuvent coexister.', 42, 331),
    ...$text('N/V ferment un anneau ; les instructions dessinent ses regions.', 42, 142),
    ...$text('Aucun ordre visuel n est infere depuis les octets du contenu.', 42, 115),
)), $lastBeads->pageEntry(), ...$lastPresentation->entries());

// PresSteps nodes form a list with two ends, not a ring.
// Only the last NA leaves the page, so it has no Next.
$exit = new N\GoToDestination(new N\LocalDestination(new Fit($last)));
$initial = new N\NavigationNode(backward: $exit);
$primary = new N\NavigationNode(new N\GoToDestination(new N\NamedDestination(new LiteralString('lecture'))), duration: new N\PresentationDuration($n(2)));
$terminal = new N\NavigationNode(forward: $exit);
$steps = (new N\PresentationSequence())->addNode($initial)->addNode($primary)->addNode($terminal);
$firstPresentation = new N\PagePresentation(new N\PresentationDuration($n(5)), new N\Transition(N\TransitionStyle::Split, new N\PresentationDuration($n(3.5)), N\TransitionDimension::Vertical, N\TransitionMotion::Outward), $steps);
$first = new PageObject($pageBox, $resources, new StreamObject(new ContentStreams(
    ...$text('Navigation PDF native', 42, 742, 26),
    ...$text('Label i / index 0', 42, 708, 15),
    ...$region(42, 440, 528, 184),
    ...$text('Article : premier bead', 61, 588, 20),
    ...$text('L article continue sur la page A-8, puis sur AA.', 61, 552),
    ...$text('B decrit les regions de cette page dans l ordre de dessin.', 61, 525),
    ...$text('Labels : i, AA, A-8', 42, 374, 18),
    ...$text('A/a utilise A...Z, AA...ZZ, AAA...ZZZ.', 42, 343),
    ...$text('PresSteps : premier <-> primaire <-> dernier', 42, 286, 17),
    ...$text('Le node primaire est choisi explicitement ; entree = NA puis Next.', 42, 254),
    ...$text('En presentation : transition 3.5 s, puis affichage 5 s.', 42, 193),
    ...$text('Les modes de presentation et articles dependent du lecteur PDF.', 42, 142),
    ...$text('Voir les signets pour les destinations nommees et explicites.', 42, 115),
)), $firstBeads->pageEntry(), ...$firstPresentation->entries($primary));

$middlePresentation = new N\PagePresentation(transition: new N\Transition(N\TransitionStyle::Fly, direction: N\TransitionDirection::None, scale: $n(0.25), rectangular: new BooleanType(true)));
$middle = new PageObject($pageBox, $resources, new StreamObject(new ContentStreams(
    ...$text('Ordre de dessin et ordre d article', 42, 742, 23),
    ...$text('Label AA / index 1', 42, 708, 15),
    ...$region(42, 460, 528, 170),
    ...$text('Note independante', 61, 592, 20),
    ...$text('Un article d un bead : N et V referencent ce meme bead.', 61, 554),
    ...$region(42, 250, 528, 172),
    ...$text('Article : troisieme bead', 61, 386, 20),
    ...$text('La suite revient au premier bead sur la page i.', 61, 348),
    ...$text('Cette page B liste Note puis Article, sans changer les anneaux.', 61, 321),
    ...$text('Fly / None / SS 0.25 : transition sans direction de vol.', 42, 191),
    ...$text('Le core declare ces relations ; le lecteur anime la transition.', 42, 142),
    ...$text('L apparence statique reste une suite explicite d operateurs.', 42, 115),
)), $middleBeads->pageEntry(), ...$middlePresentation->entries());

// PageObject must be attached before inspecting its effective entries.
// Collections then bind to existing pages without cloning or a registry.
$pages = (new PageTreeNode())->addKid($first)->addKid($middle)->addKid($last);
$firstBeads->bindToPage($first);
$middleBeads->bindToPage($middle);
$lastBeads->bindToPage($last);
$steps->bindToPage($first);

$names = new N\NamedDestinations(
    new N\NamedDestinationEntry(new LiteralString('lecture'), new N\LocalDestination(new XYZ($first, $n(42), $n(624), $n(0)))),
    new N\NamedDestinationEntry(new LiteralString('details'), new N\DestinationDictionary(new N\LocalDestination(new FitR($last, $n(42), $n(420), $n(570), $n(620))))),
);
$legacy = new N\LegacyDestinations(new N\NamedDestinationEntry(new NameType('details'), new N\LocalDestination(new Fit($middle))));
$outlines = (new OutlineDictionary())
    ->addItem(new OutlineItem(new TextString('Debut : destination explicite'), new N\LocalDestination(new Fit($first))))
    ->addItem(new OutlineItem(new TextString('AA : nom details'), new N\GoToDestination(new N\NamedDestination(new NameType('details')))))
    ->addItem(new OutlineItem(new TextString('A-8 : chaine details'), new N\GoToDestination(new N\NamedDestination(new LiteralString('details')))));
$catalog = new DocumentCatalog($pages, $labels->catalogEntry(), (new N\ArticleThreads($article, $secondArticle))->catalogEntry(), $names->catalogEntry(), $legacy->catalogEntry(), new Entry(new NameType('Outlines'), new IndirectReference($outlines)), new Entry(new NameType('PageMode'), new NameType('UseOutlines')), new Entry(new NameType('OpenAction'), new N\LocalDestination(new Fit($first))));
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/native-navigation.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
