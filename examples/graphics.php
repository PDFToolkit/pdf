<?php

declare(strict_types=1);

use PDF\Document\DocumentInformation;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Information\Title;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\GraphicsState\LineCapStyle;
use PDF\Graphics\GraphicsState\LineDashPattern;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\CurveTo;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillAndStrokePathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\LineTo;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTo;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineCapStyle;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineDashPattern;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineWidth;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetStrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\ContentStreamsResources\Operators\StrokePath;
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

$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
);

// 1. Le dessin utilise les coordonnées du papier : origine en bas à gauche,
// une unité de user space vaut ici 1/72 de pouce.
$contents = new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(24)),
    new MoveTextPosition(new NumericType(54), new NumericType(778)),
    new ShowText(new LiteralString('Traces, couleurs et matrices')),
    new EndTextObject(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(54), new NumericType(750)),
    new ShowText(new LiteralString('Chaque forme est une suite d\'operateurs PDF explicites.')),
    new EndTextObject(),

    // 2. q sauvegarde l'état, rg choisit la couleur de remplissage.
    // re construit le rectangle ; f le remplit et termine le tracé.
    new SaveGraphicsState(),
    new SetNonstrokingRGBColor(new NumericType(0.12), new NumericType(0.36), new NumericType(0.64)),
    new AppendRectangle(new NumericType(54), new NumericType(546), new NumericType(220), new NumericType(134)),
    new FillPathNonzero(),
    new RestoreGraphicsState(),

    // RG et w règlent le contour. Sa couleur est indépendante du remplissage.
    new SaveGraphicsState(),
    new SetStrokingRGBColor(new NumericType(0.12), new NumericType(0.36), new NumericType(0.64)),
    new SetLineWidth(new NumericType(3)),
    new AppendRectangle(new NumericType(321), new NumericType(546), new NumericType(220), new NumericType(134)),
    new StrokePath(),
    new RestoreGraphicsState(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(54), new NumericType(524)),
    new ShowText(new LiteralString('re + f : remplir')),
    new EndTextObject(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(321), new NumericType(524)),
    new ShowText(new LiteralString('re + S : tracer le contour')),
    new EndTextObject(),

    // 3. La courbe de Bézier part de m et utilise deux points de contrôle.
    // Les réglages précèdent le tracé ; S le termine avant le Q correspondant.
    new SaveGraphicsState(),
    new SetStrokingRGBColor(new NumericType(0.82), new NumericType(0.34), new NumericType(0.12)),
    new SetLineWidth(new NumericType(5)),
    new SetLineCapStyle(LineCapStyle::Round),
    new MoveTo(new NumericType(54), new NumericType(410)),
    new CurveTo(
        new NumericType(175),
        new NumericType(500),
        new NumericType(405),
        new NumericType(320),
        new NumericType(541),
        new NumericType(410),
    ),
    new StrokePath(),
    new RestoreGraphicsState(),

    // La ligne pointillée montre la corde de la courbe, avec un autre état.
    new SaveGraphicsState(),
    new SetStrokingRGBColor(new NumericType(0.58), new NumericType(0.62), new NumericType(0.67)),
    new SetLineWidth(new NumericType(1)),
    new SetLineDashPattern(new LineDashPattern(
        new ArrayType(new NumericType(4), new NumericType(4)),
        new NumericType(0),
    )),
    new MoveTo(new NumericType(54), new NumericType(410)),
    new LineTo(new NumericType(541), new NumericType(410)),
    new StrokePath(),
    new RestoreGraphicsState(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(54), new NumericType(356)),
    new ShowText(new LiteralString('m + c + S : courbe ; m + l + S : segment pointille')),
    new EndTextObject(),

    // 4. cm concatène [a b c d e f] à la matrice de transformation courante.
    // [1 0 0 1 130 205] translate l'origine en (130, 205).
    new SaveGraphicsState(),
    new ConcatenateMatrix(new Matrix(
        new NumericType(1),
        new NumericType(0),
        new NumericType(0),
        new NumericType(1),
        new NumericType(130),
        new NumericType(205),
    )),
    new SetNonstrokingRGBColor(new NumericType(0.87), new NumericType(0.93), new NumericType(0.98)),
    new SetStrokingRGBColor(new NumericType(0.12), new NumericType(0.36), new NumericType(0.64)),
    new SetLineWidth(new NumericType(2)),
    new AppendRectangle(new NumericType(-50), new NumericType(-40), new NumericType(100), new NumericType(80)),
    new FillAndStrokePathNonzero(),
    new RestoreGraphicsState(),

    // cos(30°) ≈ 0,8660254 et sin(30°) = 0,5 : rotation puis translation.
    // Le même rectangle local est dessiné dans un deuxième repère.
    new SaveGraphicsState(),
    new ConcatenateMatrix(new Matrix(
        new NumericType(0.8660254),
        new NumericType(0.5),
        new NumericType(-0.5),
        new NumericType(0.8660254),
        new NumericType(415),
        new NumericType(205),
    )),
    new SetNonstrokingRGBColor(new NumericType(0.87), new NumericType(0.93), new NumericType(0.98)),
    new SetStrokingRGBColor(new NumericType(0.12), new NumericType(0.36), new NumericType(0.64)),
    new SetLineWidth(new NumericType(2)),
    new AppendRectangle(new NumericType(-50), new NumericType(-40), new NumericType(100), new NumericType(80)),
    new FillAndStrokePathNonzero(),
    new RestoreGraphicsState(),

    // Q a restauré la matrice et les couleurs : ces légendes sont horizontales,
    // noires et exprimées à nouveau dans le repère de la page.
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(54), new NumericType(117)),
    new ShowText(new LiteralString('cm : translation')),
    new EndTextObject(),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(321), new NumericType(117)),
    new ShowText(new LiteralString('cm : rotation de 30 degres')),
    new EndTextObject(),
);

// 5. Le graphe de document et son écriture suivent le premier tutoriel.
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595.28), new NumericType(841.89)),
    $resources,
    new StreamObject($contents),
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$information = new DocumentInformation(new Title(new TextString('Tracés, couleurs et transformations')));
$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($catalog),
    new IndirectReference($catalog),
    new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($information))),
);
$path = $argv[1] ?? __DIR__ . '/graphics.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
