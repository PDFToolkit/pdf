<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\SampleLayout;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Functions\NumberInterval;
use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;
use PDF\Graphics\ColourSpaces\Indexed;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\GraphicsState as G;
use PDF\Graphics\Halftones as H;
use PDF\Graphics\OptionalContent as OC;
use PDF\Graphics\XObjects as X;
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
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

$n = static fn (int|float $value): NumericType => new NumericType($value);
$rgb = new DeviceColourSpace(ColourSpaceFamily::DeviceRGB);
$gray = new DeviceColourSpace(ColourSpaceFamily::DeviceGray);
$colour = static fn (int|float $r, int|float $g, int|float $b): ColourComponents => new ColourComponents($rgb, $n($r), $n($g), $n($b));
$box = static fn (int|float $width, int|float $height): Rectangle => new Rectangle($n(0), $n(0), $n($width), $n($height));
$packer = new X\SamplePacker();
$dimensions = new ImageDimensions(16, 16);
$maskValues = $indexedValues = $grayValues = $rgbValues = $spriteValues = $blueValues = $preblendedValues = $opacityValues = [];
$matte = new X\MatteColour($colour(1, 1, 1));

// Il s'agit de samples PDF normalises, sans PNG/JPEG ni lecteur de fichiers.
// Chaque pixel fournit ses composants dans l'ordre ; SamplePacker aligne
// chaque ligne a l'octet et preserve les profondeurs 1/2/4/8/16 bits.
for ($y = 0; $y < 16; ++$y) {
    for ($x = 0; $x < 16; ++$x) {
        $maskValues[] = ($x + $y) % 2;
        $indexedValues[] = intdiv($x, 4);
        $grayValues[] = $x;
        array_push($rgbValues, (int) round($x / 15 * 65535), (int) round($y / 15 * 65535), 32768);
        array_push($spriteValues, ...($x > 3 && $x < 12 && $y > 3 && $y < 12 ? [240, 70, 85] : [255, 255, 255]));
        array_push($blueValues, 20, 130, 230);
        $alpha = $x / 15;
        $opacityValues[] = (int) round($alpha * 255);
        $preblended = $matte->preblend($colour(20 / 255, 130 / 255, 230 / 255), $n($alpha));
        foreach ($preblended->numbers as $component) {
            $preblendedValues[] = (int) round($component->value * 255);
        }
    }
}
$indexed = new Indexed($rgb, new IntegerType(3), new HexadecimalString("\xEC\x45\x59\xF2\xAC\x35\x34\xAB\x87\x46\x72\xCE"));
$mask = new X\ImageMask($packer->pack($dimensions, new SampleLayout(DeviceColorSpace::Gray, 1), $maskValues));
$imageIndexed = new X\ImageXObject($packer->pack($dimensions, new SampleLayout($indexed, 2), $indexedValues));
$imageGray = new X\ImageXObject($packer->pack($dimensions, new SampleLayout($gray, 4), $grayValues));
$imageRgb = new X\ImageXObject($packer->pack($dimensions, new SampleLayout($rgb, 16), $rgbValues));

// La cle compare les entiers AVANT Decode : blanc signifie ici (255,255,255).
$key = new X\ColourKeyMask(...array_fill(0, 3, new NumberInterval(new IntegerType(255), new IntegerType(255))));
$sprite = new X\ImageXObject($packer->pack($dimensions, new SampleLayout($rgb), $spriteValues), mask: $key);

// Sans Matte, le masque a ses propres dimensions et est aussi mappe au carre
// unite. Avec Matte, les dimensions doivent correspondre : c' = alpha*c +
// (1-alpha)*matte. Les octets ci-dessus sont reellement preblendes en blanc.
$smallAlpha = new X\SoftMaskImage($packer->pack(new ImageDimensions(4, 1), new SampleLayout(DeviceColorSpace::Gray), [0, 85, 170, 255]));
$soft = new X\ImageXObject($packer->pack($dimensions, new SampleLayout($rgb), $blueValues), softMask: $smallAlpha);
$matteAlpha = new X\SoftMaskImage($packer->pack($dimensions, new SampleLayout(DeviceColorSpace::Gray), $opacityValues), $matte);
$preblendedImage = new X\ImageXObject($packer->pack($dimensions, new SampleLayout($rgb), $preblendedValues), softMask: $matteAlpha);
$images = ['Stencil1' => $mask, 'Indexed2' => $imageIndexed, 'Gray4' => $imageGray, 'Rgb16' => $imageRgb, 'ColourKey' => $sprite, 'SoftMask' => $soft, 'Matte' => $preblendedImage];

// ExtGState ne contient pas de dessin. Les rectangles selectionnent ensuite
// le mode et l'alpha ; la forme possede sa boite, sa matrice et ses ressources.
$multiply = new G\GraphicsStateParameters(new Entry(new NameType('BM'), (new G\BlendModes(G\BlendMode::Multiply))->value()), new Entry(new NameType('ca'), $n(0.75)));
$screen = new G\GraphicsStateParameters(new Entry(new NameType('BM'), new NameType('Screen')), new Entry(new NameType('ca'), $n(0.75)));
$groupResources = new ResourceDictionary(new Entry(new NameType('ExtGState'), new DictionaryType(new Entry(new NameType('Multiply'), new IndirectReference($multiply)), new Entry(new NameType('Screen'), new IndirectReference($screen)))));
$groupDrawing = static fn (NameType $mode): ContentStreams => new ContentStreams(new Op\SetNonstrokingRGBColor($n(0.94), $n(0.24), $n(0.32)), new Op\AppendRectangle($n(0), $n(0), $n(0.72), $n(0.85)), new Op\FillPathNonzero(), new Op\SetGraphicsStateParameters($mode), new Op\SetNonstrokingRGBColor($n(0.2), $n(0.45), $n(0.95)), new Op\AppendRectangle($n(0.28), $n(0.15), $n(0.72), $n(0.85)), new Op\FillPathNonzero());
$group = new X\TransparencyGroup(new G\BlendingColourSpace($rgb), new BooleanType(true));
$formMultiply = new X\FormXObject($groupDrawing(new NameType('Multiply')), $box(1, 1), $groupResources, group: $group);
$formScreen = new X\FormXObject($groupDrawing(new NameType('Screen')), $box(1, 1), $groupResources, group: $group);

// Le masque de luminosite est une autre forme, en DeviceGray. Un degradé de
// rectangles noirs/blancs fournit ici sa luminosite, sans image ni codec.
$maskOperators = [];
for ($step = 0; $step < 16; ++$step) {
    array_push($maskOperators, new Op\SetNonstrokingGray($n($step / 15)), new Op\AppendRectangle($n($step / 16), $n(0), $n(1 / 16), $n(1)), new Op\FillPathNonzero());
}
$maskGroup = new X\FormXObject(new ContentStreams(...$maskOperators), $box(1, 1), new ResourceDictionary(), group: new X\TransparencyGroup(new G\BlendingColourSpace($gray), new BooleanType(true)));
$luminosity = new G\SoftMask(G\SoftMaskSubtype::Luminosity, $maskGroup);
$maskedState = new G\GraphicsStateParameters(new Entry(new NameType('SMask'), $luminosity));
$formMasked = new X\FormXObject(new ContentStreams(new Op\SetGraphicsStateParameters(new NameType('Mask')), new Op\SetNonstrokingRGBColor($n(0.14), $n(0.62), $n(0.48)), new Op\AppendRectangle($n(0), $n(0), $n(1), $n(1)), new Op\FillPathNonzero()), $box(1, 1), new ResourceDictionary(new Entry(new NameType('ExtGState'), new DictionaryType(new Entry(new NameType('Mask'), new IndirectReference($maskedState))))));

// Les halftones sont des objets PDF de sortie, pas des textures simulees par
// le core. Ces petits tableaux reels restent disponibles dans les ressources.
$thresholds = new H\RectangularHalftone(new H\ThresholdRectangle(new ImageDimensions(2, 2), new H\ThresholdArray(8, 4, [0, 85, 170, 255])));
$thresholds16 = new H\SixteenBitHalftone(new H\ThresholdRectangle(new ImageDimensions(2, 1), new H\ThresholdArray(16, 2, [0x1234, 0xFFFF])));
$halftoneState = new G\GraphicsStateParameters(new Entry(new NameType('HT'), new IndirectReference($thresholds)));
$halftone16State = new G\GraphicsStateParameters(new Entry(new NameType('HT'), new IndirectReference($thresholds16)));

// Les groupes sont declares une fois dans OCProperties. Leurs etats sont
// distincts des dictionnaires : ici Visible est ON et Hidden est OFF.
$visible = new OC\OptionalContentGroup(new TextString('Couche visible'), usage: new OC\GroupUsage(new OC\EventUsage(OC\UsageEvent::View, new BooleanType(true))));
$hidden = new OC\OptionalContentGroup(new TextString('Couche masquee'));
$visibleAssociation = new OC\OptionalContentAssociation(new NameType('Visible'), $visible);
$hiddenAssociation = new OC\OptionalContentAssociation(new NameType('Hidden'), $hidden);
$membership = new OC\OptionalContentMembership(new OC\GroupCollection($visible, $hidden), OC\VisibilityPolicy::AnyOn, new OC\VisibilityExpression(OC\VisibilityOperator::And, $visible, new OC\VisibilityExpression(OC\VisibilityOperator::Not, $hidden)));
$membershipAssociation = new OC\OptionalContentAssociation(new NameType('Condition'), $membership);
$properties = new OC\OptionalContentProperties(new OC\GroupCollection($visible, $hidden), new OC\Configuration(off: new OC\GroupCollection($hidden), order: new OC\GroupOrder(new OC\OrderBranch(new TextString('Demonstration'), $visible, $hidden))));
$forms = ['Multiply' => $formMultiply, 'Screen' => $formScreen, 'Luminosity' => $formMasked];
$resources = new ResourceDictionary(
    new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))))),
    new Entry(new NameType('XObject'), new DictionaryType(...array_map(static fn (string $name): Entry => new Entry(new NameType($name), new IndirectReference($images[$name])), array_keys($images)), ...array_map(static fn (string $name): Entry => new Entry(new NameType($name), new IndirectReference($forms[$name])), array_keys($forms)))),
    new Entry(new NameType('Properties'), new DictionaryType($visibleAssociation->propertyEntry(), $hiddenAssociation->propertyEntry(), $membershipAssociation->propertyEntry())),
    new Entry(new NameType('ExtGState'), new DictionaryType(new Entry(new NameType('Halftone8'), new IndirectReference($halftoneState)), new Entry(new NameType('Halftone16'), new IndirectReference($halftone16State)))),
);
/** @return list<Operator> */
$text = static fn (string $label, int $x, int $y, int $size = 11): array => [new Op\SetNonstrokingRGBColor($n(0.12), $n(0.16), $n(0.22)), new Op\BeginTextObject(), new Op\SetTextFontAndSize(new NameType('F1'), $n($size)), new Op\MoveTextPosition($n($x), $n($y)), new Op\ShowText(new LiteralString($label)), new Op\EndTextObject()];
/** @return list<Operator> */
$invoke = static fn (string $name, int $x, int $y, int $width, int $height): array => [new Op\SaveGraphicsState(), new Op\ConcatenateMatrix(new Matrix($n($width), $n(0), $n(0), $n($height), $n($x), $n($y))), new Op\SetNonstrokingRGBColor($n(0.68), $n(0.22), $n(0.72)), new Op\InvokeXObject(new NameType($name)), new Op\RestoreGraphicsState()];
$first = [...$text('Images PDF natives', 42, 742, 25), ...$text('Samples, Decode et masques : aucun fichier image externe.', 42, 712)];
$labels = ['Stencil1' => '1 bit - stencil, couleur courante', 'Indexed2' => '2 bits - palette Indexed', 'Gray4' => '4 bits - DeviceGray', 'Rgb16' => '16 bits - DeviceRGB', 'ColourKey' => '8 bits - blanc masque par cle', 'SoftMask' => 'SMask - dimensions propres', 'Matte' => 'SMask + Matte - preblend blanc'];
foreach (array_keys($images) as $index => $name) {
    $x = 42 + $index % 3 * 176;
    $y = 532 - intdiv($index, 3) * 176;
    array_push($first, new Op\SetNonstrokingRGBColor($n(0.94), $n(0.96), $n(0.98)), new Op\AppendRectangle($n($x), $n($y), $n(152), $n(132)), new Op\FillPathNonzero(), ...$invoke($name, $x, $y, 152, 132), ...$text($labels[$name], $x, $y - 19, 10));
}
array_push($first, ...$text('Chaque ligne est alignee a l octet ; les valeurs sont intercalees par pixel.', 42, 105), ...$text('La geometrie de placement est independante des dimensions en samples.', 42, 84));
$second = [...$text('Formes, transparence et couches', 42, 742, 23), ...$text('Les relations sont explicites et verifiees dans le graphe du document.', 42, 712)];
foreach (['Multiply' => 'Groupe isole - Multiply / 0.75', 'Screen' => 'Groupe isole - Screen / 0.75', 'Luminosity' => 'SMask - groupe de luminosite'] as $name => $label) {
    $index = array_search($name, array_keys($forms), true);
    $x = 42 + $index * 176;
    array_push($second, ...$invoke($name, $x, 506, 152, 158), ...$text($label, $x, 486, 10));
}
$second = [...$second, ...$text('OptionalContent : deux groupes et une expression VE', 42, 428, 16), $visibleAssociation->begin(), new Op\SetNonstrokingRGBColor($n(0.15), $n(0.6), $n(0.47)), new Op\AppendRectangle($n(42), $n(290), $n(240), $n(108)), new Op\FillPathNonzero(), ...$text('Visible : ON', 61, 335, 18), new Op\EndMarkedContent()];
$second = [...$second, $hiddenAssociation->begin(), new Op\SetNonstrokingRGBColor($n(0.95), $n(0.25), $n(0.32)), new Op\AppendRectangle($n(312), $n(290), $n(240), $n(108)), new Op\FillPathNonzero(), ...$text('Hidden : OFF', 329, 335, 18), new Op\EndMarkedContent()];
$second = [...$second, ...$text('La zone de droite est cachee par OCProperties / D / OFF.', 42, 260), $membershipAssociation->begin(), ...$text('VE : Visible AND NOT Hidden => contenu visible', 42, 222, 15), new Op\EndMarkedContent(), ...$text('Le panneau des couches peut changer ces etats dans un lecteur PDF.', 42, 188)];
array_push($second, ...$text('Halftones : les tables 8/16 bits sont embarquees dans ExtGState.', 42, 116), ...$text('Leur aspect depend du dispositif de sortie, qui effectue le screening.', 42, 94));
$pageBox = $box(612, 792);
$pages = (new PageTreeNode())->addKid(new PageObject($pageBox, $resources, new StreamObject(new ContentStreams(...$first))))->addKid(new PageObject($pageBox, $resources, new StreamObject(new ContentStreams(...$second))));
$catalog = new DocumentCatalog($pages, $properties->catalogEntry());
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/native-graphics.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
