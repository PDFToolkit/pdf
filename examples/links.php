<?php

declare(strict_types=1);

use PDF\Document\Destinations\Fit;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Specification\Dictionary\AnnotationDictionaryTable164\BorderEntry;
use PDF\Specification\Dictionary\AnnotationDictionaryTable164\ContentsEntry;
use PDF\Specification\Dictionary\AnnotationDictionaryTable164\RectEntry;
use PDF\Specification\Dictionary\AnnotationDictionaryTable164\TypeEntry;
use PDF\Specification\Dictionary\LinkAnnotationTable173;
use PDF\Specification\Dictionary\LinkAnnotationTable173\AEntry;
use PDF\Specification\Dictionary\LinkAnnotationTable173\DestEntry;
use PDF\Specification\Dictionary\LinkAnnotationTable173\SubtypeEntry;
use PDF\Specification\Dictionary\PageObjectTable30\AnnotsEntry;
use PDF\Specification\Dictionary\URIActionTable206;
use PDF\Specification\Dictionary\URIActionTable206\SEntry;
use PDF\Specification\Dictionary\URIActionTable206\URIEntry;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. Blue text is only drawing. Making it clickable requires
// a /Link annotation in /Annots with its rectangle and target.
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));
$mediaBox = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842));

// 2. Building the target page first lets the internal link target this
// instance. Its position is determined later by addKid() order.
$targetPage = new PageObject($mediaBox, $resources, new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(22)),
    new MoveTextPosition(new NumericType(54), new NumericType(770)),
    new ShowText(new LiteralString('Vous avez suivi le lien interne.')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new MoveTextPosition(new NumericType(0), new NumericType(-40)),
    new ShowText(new LiteralString('La destination /Fit ajuste cette seconde page dans la fenetre.')),
    new EndTextObject(),
)));

// 3. A URI action describes external navigation: /S /URI selects its
// type; /URI contains the ASCII address. Table206 connects the class
// to its ISO definition. It is not a convenience API.
$externalAction = new URIActionTable206(
    new SEntry(new NameType('URI')),
    new URIEntry(new LiteralString('https://github.com/roukmoute/pdf')),
);
$externalLink = new LinkAnnotationTable173(
    new TypeEntry(new NameType('Annot')),
    new SubtypeEntry(new NameType('Link')),
    new RectEntry(new Rectangle(new NumericType(54), new NumericType(671), new NumericType(380), new NumericType(693))),
    new BorderEntry(new ArrayType(new NumericType(0), new NumericType(0), new NumericType(0))),
    new ContentsEntry(new TextString('Ouvrir le dépôt GitHub du projet')),
    new AEntry($externalAction),
);

// 4. The internal link has /Dest instead of /A. Do not supply both
// for one annotation. Fit produces [page-reference /Fit].
$internalLink = new LinkAnnotationTable173(
    new TypeEntry(new NameType('Annot')),
    new SubtypeEntry(new NameType('Link')),
    new RectEntry(new Rectangle(new NumericType(54), new NumericType(611), new NumericType(380), new NumericType(633))),
    new BorderEntry(new ArrayType(new NumericType(0), new NumericType(0), new NumericType(0))),
    new ContentsEntry(new TextString('Aller à la deuxième page')),
    new DestEntry(new Fit($targetPage)),
);

// 5. /Rect uses page coordinates with the origin at the lower left.
// Text is positioned at y=678 and y=618 inside these rectangles.
// /Border [0 0 0] suppresses the annotation's automatic border.
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(22)),
    new MoveTextPosition(new NumericType(54), new NumericType(770)),
    new ShowText(new LiteralString('Deux liens, deux mecanismes PDF')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new MoveTextPosition(new NumericType(0), new NumericType(-40)),
    new ShowText(new LiteralString('Cliquez sur les textes bleus dans un lecteur PDF interactif.')),
    new SetNonstrokingRGBColor(new NumericType(0.1), new NumericType(0.3), new NumericType(0.75)),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(15)),
    new MoveTextPosition(new NumericType(0), new NumericType(-52)),
    new ShowText(new LiteralString('Ouvrir le depot GitHub du projet')),
    new MoveTextPosition(new NumericType(0), new NumericType(-60)),
    new ShowText(new LiteralString('Aller a la deuxieme page')),
    new EndTextObject(),
));

// 6. Each annotation is referenced indirectly by the /Annots array.
// The LinkAnnotation schema also composes common Table164 entries.
// The page must receive its entries before attachment to the tree.
$firstPage = new PageObject($mediaBox, $resources, $contents, new AnnotsEntry(new ArrayType(
    new IndirectReference($externalLink),
    new IndirectReference($internalLink),
)));
$pages = (new PageTreeNode())->addKid($firstPage)->addKid($targetPage);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/links.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
