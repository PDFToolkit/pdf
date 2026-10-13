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
use PDF\Specification\Dictionary\ImageDictionaryTable89\SubtypeEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\TypeEntry;
use PDF\Specification\Dictionary\ImageDictionaryTable89\WidthEntry;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Filters\EarlyChange;
use PDF\Syntax\Filters\FixedPngFilterSelection;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\LZWDecode;
use PDF\Syntax\Filters\PngPredictor;
use PDF\Syntax\Filters\PngRowFilter;
use PDF\Syntax\Filters\PredictorLayout;
use PDF\Syntax\Filters\TiffPredictor;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. Construire les mêmes pixels RGB pour les trois images. Les lignes sont
// rangées de haut en bas ; chaque pixel contient trois composantes de huit bits.
// Ces octets sont des échantillons PDF bruts, pas un fichier PNG ou TIFF importé.
$width = 64;
$height = 32;
$pixels = '';
for ($y = 0; $y < $height; ++$y) {
    for ($x = 0; $x < $width; ++$x) {
        $pixels .= chr(intdiv($x * 255, $width - 1)) . chr(intdiv($y * 255, $height - 1)) . chr(intdiv(($x + $y) * 255, $width + $height - 2));
    }
}
$imageDictionary = new ImageDictionaryTable89(
    new TypeEntry(new NameType('XObject')),
    new SubtypeEntry(new NameType('Image')),
    new WidthEntry(new IntegerType($width)),
    new HeightEntry(new IntegerType($height)),
    new ColorSpaceEntry(new NameType('DeviceRGB')),
    new BitsPerComponentEntry(new IntegerType(8)),
);

// 2. Décrire les lignes de prédiction avec les valeurs PDF de la table 8 :
// Colors=3, Columns=64, BitsPerComponent=8 par défaut. TIFF soustrait le sample
// précédent de la même composante. PNG applique sa prédiction à chaque octet
// et préfixe chaque ligne par le numéro du filtre utilisé.
$layout = new PredictorLayout(new IntegerType(3), new IntegerType($width));
$paeth = new PngPredictor($layout, new FixedPngFilterSelection(PngRowFilter::Paeth));
$tiff = new TiffPredictor($layout);
$adaptive = new PngPredictor($layout);

// 3. Le filtre compose la prédiction avant la compression. StreamObject produit
// /Filter, /DecodeParms et la longueur encodée à partir de ces mêmes objets.
// EarlyChange détermine uniquement les frontières 9/10/11/12 bits des codes LZW.
$images = [
    new StreamObject($pixels, $imageDictionary, new FlateDecode(6, $paeth)),
    new StreamObject($pixels, $imageDictionary, new LZWDecode(new EarlyChange(new IntegerType(0)), $tiff)),
    new StreamObject($pixels, $imageDictionary, new LZWDecode(new EarlyChange(new IntegerType(1)), $adaptive)),
];
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))),
    new XObject(new DictionaryType(
        new Entry(new NameType('FlatePaeth'), new IndirectReference($images[0])),
        new Entry(new NameType('LzwTiff'), new IndirectReference($images[1])),
        new Entry(new NameType('LzwPng'), new IndirectReference($images[2])),
    )),
);
$operators = [
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(22)),
    new MoveTextPosition(new NumericType(42), new NumericType(784)),
    new ShowText(new LiteralString('Les memes pixels, trois encodages PDF')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(0), new NumericType(-38)),
    new ShowText(new LiteralString('La prediction transforme les octets avant LZW ou zlib.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-22)),
    new ShowText(new LiteralString('Le lecteur inverse la compression, puis la prediction declaree.')),
    new EndTextObject(),
];

// 4. Les matrices changent uniquement la taille et l'emplacement à l'affichage.
// Les images conservent toutes 64 × 32 pixels ; seuls leurs octets encodés diffèrent.
foreach ([['FlatePaeth', 'Flate + PNG Paeth'], ['LzwTiff', 'LZW EarlyChange=0 + TIFF'], ['LzwPng', 'LZW EarlyChange=1 + PNG adaptatif']] as $index => [$name, $label]) {
    $top = 636 - $index * 170;
    array_push(
        $operators,
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
        new MoveTextPosition(new NumericType(42), new NumericType($top + 16)),
        new ShowText(new LiteralString($label)),
        new EndTextObject(),
        new SaveGraphicsState(),
        new ConcatenateMatrix(new Matrix(new NumericType(256), new NumericType(0), new NumericType(0), new NumericType(128), new NumericType(42), new NumericType($top - 128))),
        new InvokeXObject(new NameType($name)),
        new RestoreGraphicsState(),
    );
}
$content = new StreamObject(new ContentStreams(...$operators));
$pages = new PageTreeNode();
$pages->addKid(new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)), $resources, $content));
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/filters-predictors.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
