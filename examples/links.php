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

// 1. Un texte bleu est seulement un dessin. Pour le rendre cliquable, il
// faut une annotation /Link dans /Annots, avec son rectangle et sa cible.
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));
$mediaBox = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842));

// 2. Construire d'abord la page cible permet au lien interne de viser cette
// instance. Sa position sera déterminée plus tard par l'ordre de addKid().
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

// 3. Une action URI décrit la navigation externe : /S /URI sélectionne son
// type ; /URI contient l'adresse ASCII. Le nom Table206 rattache la classe
// à sa définition ISO. Il ne s'agit pas d'une API de confort.
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

// 4. Le lien interne possède /Dest au lieu de /A. Ne pas fournir les deux
// pour la même annotation. Fit produit [référence-à-la-page /Fit].
$internalLink = new LinkAnnotationTable173(
    new TypeEntry(new NameType('Annot')),
    new SubtypeEntry(new NameType('Link')),
    new RectEntry(new Rectangle(new NumericType(54), new NumericType(611), new NumericType(380), new NumericType(633))),
    new BorderEntry(new ArrayType(new NumericType(0), new NumericType(0), new NumericType(0))),
    new ContentsEntry(new TextString('Aller à la deuxième page')),
    new DestEntry(new Fit($targetPage)),
);

// 5. /Rect emploie les coordonnées de la page, avec origine en bas à gauche.
// Les textes sont placés à y=678 et y=618, à l'intérieur de ces rectangles.
// /Border [0 0 0] supprime la bordure automatique de l'annotation.
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

// 6. Chaque annotation est référencée indirectement par le tableau /Annots.
// Le schéma de LinkAnnotation compose aussi les entrées communes Table164.
// La page doit recevoir ses entrées avant son rattachement à l'arbre.
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
