<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Resources\Shading;
use PDF\FileStructure;
use PDF\Functions\Domain;
use PDF\Functions\ExponentialInterpolationFunction;
use PDF\Functions\NumberArray;
use PDF\Functions\NumberInterval;
use PDF\Functions\Range;
use PDF\Specification\Dictionary\ShadingDictionaryTable78\ColorSpaceEntry;
use PDF\Specification\Dictionary\ShadingDictionaryTable78\ShadingTypeEntry;
use PDF\Specification\Dictionary\Type2ShadingDictionaryTable80;
use PDF\Specification\Dictionary\Type2ShadingDictionaryTable80\CoordsEntry;
use PDF\Specification\Dictionary\Type2ShadingDictionaryTable80\DomainEntry;
use PDF\Specification\Dictionary\Type2ShadingDictionaryTable80\ExtendEntry;
use PDF\Specification\Dictionary\Type2ShadingDictionaryTable80\FunctionEntry;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ClipPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\EndPath;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\PaintShading;
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
use PDF\Syntax\Object\ArrayType;
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

// 1. La fonction PDF type 2 reçoit un paramètre t dans [0, 1] et renvoie
//    trois composantes RGB. Avec N=1, C(t)=C0+t*(C1-C0) est linéaire.
//    Range explicite les trois intervalles de sortie, un par composante.
$unitInterval = new NumberInterval(new NumericType(0), new NumericType(1));
$domain = new Domain($unitInterval);
$function = new ExponentialInterpolationFunction(
    $domain,
    new NumericType(1),
    new NumberArray(new NumericType(0.10), new NumericType(0.65), new NumericType(0.85)),
    new NumberArray(new NumericType(1.00), new NumericType(0.72), new NumericType(0.20)),
    new Range($unitInterval, $unitInterval, $unitInterval),
);

// 2. /ShadingType 2 est un dégradé axial. /Coords décrit ses deux extrémités
//    dans les coordonnées de la page : [64 500 548 500]. À gauche t=0,
//    à droite t=1 ; la couleur reste constante perpendiculairement à l'axe.
//    Les entrées communes viennent de la table 78, les spécifiques de la 80.
//    La fonction reste un objet indirect distinct du dictionnaire de shading.
$shading = new Type2ShadingDictionaryTable80(
    new ShadingTypeEntry(new IntegerType(2)),
    new ColorSpaceEntry(new NameType('DeviceRGB')),
    new CoordsEntry(new NumberArray(
        new NumericType(64),
        new NumericType(500),
        new NumericType(548),
        new NumericType(500),
    )),
    new DomainEntry($domain),
    new FunctionEntry(new IndirectReference($function)),
    new ExtendEntry(new ArrayType(new BooleanType(false), new BooleanType(false))),
);

// 3. /Shading associe le nom local /Sh1 à ce dictionnaire. L'opérateur sh
//    invoquera cette ressource ; aucun bitmap ni bandes colorées ne sont créés.
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
    new Shading(new DictionaryType(
        new Entry(new NameType('Sh1'), new IndirectReference($shading)),
    )),
);

// 4. Le shading remplit sa zone dans la limite du clipping courant. re crée
//    un rectangle ; W demande d'en faire la zone de clipping ; n termine le
//    chemin sans le peindre et applique ce clipping. q/Q limitent son effet
//    au dégradé : les textes qui suivent retrouvent le clipping de la page.
$contents = new StreamObject(new ContentStreams(
    new SetNonstrokingRGBColor(new NumericType(0.08), new NumericType(0.18), new NumericType(0.28)),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(26)),
    new MoveTextPosition(new NumericType(64), new NumericType(714)),
    new ShowText(new LiteralString('Un degrade calcule par le lecteur PDF')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new MoveTextPosition(new NumericType(0), new NumericType(-30)),
    new ShowText(new LiteralString('Fonction type 2 + shading axial type 2 + clipping rectangulaire')),
    new EndTextObject(),
    new SaveGraphicsState(),
    new AppendRectangle(new NumericType(64), new NumericType(400), new NumericType(484), new NumericType(200)),
    new ClipPathNonzero(),
    new EndPath(),
    new PaintShading(new NameType('Sh1')),
    new RestoreGraphicsState(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(14)),
    new MoveTextPosition(new NumericType(64), new NumericType(374)),
    new ShowText(new LiteralString('t = 0  |  C0 = [0.10 0.65 0.85]')),
    new MoveTextPosition(new NumericType(250), new NumericType(0)),
    new ShowText(new LiteralString('t = 1  |  C1 = [1.00 0.72 0.20]')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new MoveTextPosition(new NumericType(-250), new NumericType(-58)),
    new ShowText(new LiteralString('C(t) = C0 + t * (C1 - C0), car N = 1.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-24)),
    new ShowText(new LiteralString('Coords fixe un axe ; le rectangle de clipping fixe la surface visible.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-24)),
    new ShowText(new LiteralString('Ce texte reste visible hors du rectangle grace a la restauration Q.')),
    new EndTextObject(),
));

// 5. Le même graphe document que dans les autres tutoriels conduit au fichier.
//    Les références indirectes sont numérotées au moment de la sérialisation.
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/gradient.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
