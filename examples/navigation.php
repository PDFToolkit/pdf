<?php

declare(strict_types=1);

use PDF\Document\Destinations\Fit;
use PDF\Document\Destinations\XYZ;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\PageMode;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Names;
use PDF\Syntax\DocumentStructure\CatalogDictionary\OpenAction;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Outlines;
use PDF\Syntax\DocumentStructure\CatalogDictionary\PageMode as CatalogPageMode;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\NameDictionary;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. All three pages share the same font and resource dictionary.
// /F1 is a local name used by Tf, not the font name.
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));
$mediaBox = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842));
$pages = new PageTreeNode();
$chapterPages = [];

// 2. addKid() order is reading order. Content strings remain
// ASCII here; TextString will be used for bookmark titles.
foreach (['Le graphe du document', 'Les destinations', 'Les arbres de noms'] as $index => $chapter) {
    $contents = new StreamObject(new ContentStreams(
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(22)),
        new MoveTextPosition(new NumericType(54), new NumericType(770)),
        new ShowText(new LiteralString('Chapitre ' . ($index + 1) . ' : ' . $chapter)),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
        new MoveTextPosition(new NumericType(0), new NumericType(-40)),
        new ShowText(new LiteralString('Ouvrez le panneau des signets pour naviguer entre les pages.')),
        new MoveTextPosition(new NumericType(0), new NumericType(-26)),
        new ShowText(new LiteralString('Les signets referencent les objets Page, pas des numeros de page.')),
        new EndTextObject(),
    ));
    $page = new PageObject($mediaBox, $resources, $contents);
    $pages->addKid($page);
    $chapterPages[] = $page;
}

// 3. /Fit fits the whole page. /XYZ selects a point and zoom (1 = 100%).
// These destination arrays contain an indirect reference to the Page.
$firstPage = new Fit($chapterPages[0]);
$secondPage = new XYZ($chapterPages[1], new NumericType(0), new NumericType(842), new NumericType(1));
$thirdPage = new Fit($chapterPages[2]);

// 4. An outline (Outlines) is a hierarchy separate from the page tree.
// /Parent, /Prev, /Next, /First, /Last and /Count are derived from
// attachments. Each item must belong to only one parent.
$outline = new OutlineDictionary();
$overview = new OutlineItem(new TextString('Comprendre la navigation'), $firstPage);
$overview->addItem(new OutlineItem(new TextString('1. Le graphe du document'), $firstPage));
$overview->addItem(new OutlineItem(new TextString('2. Les destinations - zoom à 100 %'), $secondPage));
$overview->addItem(new OutlineItem(new TextString('3. Les arbres de noms'), $thirdPage));
$outline->addItem($overview);

// 5. /Names /Dests associates a stable string with an explicit destination.
// A NameTree key is a byte string, not a NameType (/Nom).
// NameTree sorts its keys; links can then target a key without
// knowing the chapter's position in the page tree.
$destinations = new NameTree(
    new NameTreeEntry(new LiteralString('chapitre-3'), $thirdPage),
    new NameTreeEntry(new LiteralString('chapitre-1'), $firstPage),
    new NameTreeEntry(new LiteralString('chapitre-2'), $secondPage),
);
$names = new NameDictionary(new Entry(new NameType('Dests'), new IndirectReference($destinations)));

// 6. The catalog makes outlines and destinations accessible. /Outlines
// requires an indirect reference. /PageMode requests bookmark display;
// readers remain free to honor this interface preference.
$catalog = new DocumentCatalog(
    $pages,
    new Outlines(new IndirectReference($outline)),
    new Names($names),
    new CatalogPageMode(PageMode::UseOutlines),
    new OpenAction($firstPage),
);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/navigation.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
