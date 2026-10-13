<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Resources\XObject;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Specification\Dictionary\ImageDictionaryTable89;
use PDF\Specification\Dictionary\ImageDictionaryTable89\BitsPerComponentEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\ColorSpaceEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\HeightEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\InterpolateEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\SubtypeEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\TypeEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\WidthEntry;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\InlineImage;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Filters\FlateDecode;
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

// 1. Construire des pixels, pas un fichier PNG : chaque pixel contient les
//    trois octets R, G, B. 16 × 16 × 3 = 768 octets, rangés ligne par ligne.
//    La palette ne contient pas de terminateur EI ambigu pour l'image inline.
$palette = [
    [255, 105, 85],
    [255, 204, 96],
    [74, 210, 188],
    [96, 160, 245],
    [181, 133, 235],
    [255, 245, 220],
];
$pixels = '';
for ($y = 0; $y < 16; ++$y) {
    for ($x = 0; $x < 16; ++$x) {
        $colour = $palette[(intdiv($x, 4) + intdiv($y, 4)) % count($palette)];
        $pixels .= chr($colour[0]) . chr($colour[1]) . chr($colour[2]);
    }
}

// 2. Décrire les échantillons : /Width et /Height sont des pixels ; 8 bits par
//    composante donnent 256 valeurs ; /DeviceRGB attend trois composantes.
//    Ces entrées proviennent de la table 89 de la norme. Interpolate=false
//    conserve les contours du motif lors de son agrandissement.
$width = new WidthEntry(new IntegerType(16));
$height = new HeightEntry(new IntegerType(16));
$bits = new BitsPerComponentEntry(new IntegerType(8));
$colourSpace = new ColorSpaceEntry(new NameType('DeviceRGB'));
$interpolate = new InterpolateEntry(new BooleanType(false));
$imageDictionary = new ImageDictionaryTable89(
    new TypeEntry(new NameType('XObject')),
    new SubtypeEntry(new NameType('Image')),
    $width,
    $height,
    $bits,
    $colourSpace,
    $interpolate,
);

// 3. L'image XObject est un flux séparé, référencé sous /Im1 dans /XObject.
//    StreamObject compresse les pixels avec zlib, puis produit /Filter
//    /FlateDecode et /Length. Les octets d'origine restent le point de départ.
$image = new StreamObject($pixels, $imageDictionary, new FlateDecode());
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
    new XObject(new DictionaryType(
        new Entry(new NameType('Im1'), new IndirectReference($image)),
    )),
);

// 4. L'image inline contient le dictionnaire et les octets directement dans
//    le flux de contenu : InlineImage écrit BI, ID puis EI en une instruction.
//    Elle n'a ni /Type, ni /Subtype, ni entrée dans les ressources /XObject.
//    Ici les pixels sont bruts : aucun /Filter n'est déclaré.
$inlineImage = new InlineImage(new DictionaryType(
    $width,
    $height,
    $bits,
    $colourSpace,
    $interpolate,
), $pixels);

// 5. Une image PDF occupe le carré unité. cm transforme ce carré en un carré
//    de 216 × 216 points et le translate. La résolution de 16 × 16 pixels
//    ne détermine donc pas sa taille sur la page. q/Q encadrent chaque cm.
$contents = new StreamObject(new ContentStreams(
    new SetNonstrokingRGBColor(new NumericType(0.08), new NumericType(0.18), new NumericType(0.28)),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(24)),
    new MoveTextPosition(new NumericType(64), new NumericType(714)),
    new ShowText(new LiteralString('Deux representations de la meme image')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new MoveTextPosition(new NumericType(0), new NumericType(-30)),
    new ShowText(new LiteralString('16 x 16 pixels RGB - 8 bits par composante - affichage 216 x 216 points')),
    new EndTextObject(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(16)),
    new MoveTextPosition(new NumericType(64), new NumericType(640)),
    new ShowText(new LiteralString('XObject /Im1 + FlateDecode')),
    new EndTextObject(),
    new SaveGraphicsState(),
    new ConcatenateMatrix(new Matrix(
        new NumericType(216),
        new NumericType(0),
        new NumericType(0),
        new NumericType(216),
        new NumericType(64),
        new NumericType(400),
    )),
    new InvokeXObject(new NameType('Im1')),
    new RestoreGraphicsState(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(16)),
    new MoveTextPosition(new NumericType(332), new NumericType(640)),
    new ShowText(new LiteralString('Image inline BI / ID / EI')),
    new EndTextObject(),
    new SaveGraphicsState(),
    new ConcatenateMatrix(new Matrix(
        new NumericType(216),
        new NumericType(0),
        new NumericType(0),
        new NumericType(216),
        new NumericType(332),
        new NumericType(400),
    )),
    $inlineImage,
    new RestoreGraphicsState(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new MoveTextPosition(new NumericType(64), new NumericType(370)),
    new ShowText(new LiteralString('Flux separe, reference reutilisable.')),
    new MoveTextPosition(new NumericType(268), new NumericType(0)),
    new ShowText(new LiteralString('768 octets dans le contenu de page.')),
    new MoveTextPosition(new NumericType(-268), new NumericType(-60)),
    new ShowText(new LiteralString('Le nom /Im1 est local aux ressources de la page.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-24)),
    new ShowText(new LiteralString('La matrice fixe la taille et la position ; q/Q isole cette transformation.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-24)),
    new ShowText(new LiteralString('Les deux images utilisent exactement les memes echantillons RGB.')),
    new EndTextObject(),
));

// 6. Relier la page, son arbre et le catalogue ; le writer découvre les objets
//    indirects et attribue leurs numéros à partir de ce graphe.
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/images.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
