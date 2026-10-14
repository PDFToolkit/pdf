<?php

declare(strict_types=1);

use PDF\Document\Destinations\Fit;
use PDF\Document\DocumentInformation;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Information\Title;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Outlines;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
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

// 1. The graph retains one identity per font. Helvetica-Bold is used
// on the first page; Courier is shared by the following two.
// These dependencies require two distinct physical locations in the file.
$heading = new Type1Font(StandardFont::HelveticaBold);
$later = new Type1Font(StandardFont::Courier);
$common = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($heading)),
)));
$following = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($heading)),
    new Entry(new NameType('F2'), new IndirectReference($later)),
)));

// 2. Pages-node values are inherited in the model. Linearization also
// writes them in every Page dictionary: readers can request one page
// without first loading its ancestors in the page tree.
$pages = new PageTreeNode(
    new Entry(new NameType('MediaBox'), new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842))),
    new Entry(new NameType('Resources'), new IndirectReference($common)),
);
$leaves = [];
foreach ([
    ['Une premiere page disponible rapidement', 'F1', 'Le catalogue, les indices et cette page arrivent au debut du fichier.'],
    ['Des objets partages, une seule identite', 'F2', 'Courier est commun aux pages 2 et 3, dans la section des objets partages.'],
    ['Deux sections de references croisees', 'F2', 'Le lecteur retrouve aussi les metadonnees et les signets en fin de fichier.'],
] as $index => [$title, $bodyFont, $description]) {
    $content = new StreamObject(new ContentStreams(
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
        new MoveTextPosition(new NumericType(42), new NumericType(778)),
        new ShowText(new LiteralString($title)),
        new SetTextFontAndSize(new NameType($bodyFont), new NumericType(10)),
        new MoveTextPosition(new NumericType(0), new NumericType(-42)),
        new ShowText(new LiteralString($description)),
        new MoveTextPosition(new NumericType(0), new NumericType(-32)),
        new ShowText(new LiteralString('Page ' . ($index + 1) . ' / 3 - Annexe F, ISO 32000-1:2008')),
        new EndTextObject(),
    ));
    $leaf = new PageObject(null, $index === 0 ? null : new IndirectReference($following), $content);
    $pages->addKid($leaf);
    $leaves[] = $leaf;
}

// 3. The outline and information are document objects. They are grouped
// after pages; named hint tables provide their byte ranges.
$outline = new OutlineDictionary();
foreach ($leaves as $index => $leaf) {
    $outline->addItem(new OutlineItem(new TextString('Page ' . ($index + 1)), new Fit($leaf)));
}
$catalog = new DocumentCatalog($pages, new Outlines(new IndirectReference($outline)));
$information = new DocumentInformation(new Title(new TextString('Un document PDF linearise, objet par objet')));
$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($catalog),
    new IndirectReference($catalog),
    new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($information)))
);

// 4. The writer calculates order, renumbers references in its own context,
// encodes binary hints and writes exact offsets. The supplied graph remains
// intact; no external program is needed to produce these bytes.
$path = $argv[1] ?? __DIR__ . '/linearized.pdf';
(new LinearizedWriter())->writeTo($file, $path);
echo $path . PHP_EOL;
