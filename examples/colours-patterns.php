<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Functions\Calculator\Number;
use PDF\Functions\Calculator\Operator as CalculatorOperator;
use PDF\Functions\Calculator\Procedure;
use PDF\Functions\Domain;
use PDF\Functions\ExponentialInterpolationFunction;
use PDF\Functions\NumberArray;
use PDF\Functions\NumberInterval;
use PDF\Functions\PostScriptCalculatorFunction;
use PDF\Functions\Range;
use PDF\Graphics\ColourSpaces\CalGray;
use PDF\Graphics\ColourSpaces\CalRGB;
use PDF\Graphics\ColourSpaces\ColourComponentNames;
use PDF\Graphics\ColourSpaces\ColourComponents;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\DeviceColourSpace;
use PDF\Graphics\ColourSpaces\DeviceN;
use PDF\Graphics\ColourSpaces\ICCBased;
use PDF\Graphics\ColourSpaces\ICCProfile;
use PDF\Graphics\ColourSpaces\Indexed;
use PDF\Graphics\ColourSpaces\Lab;
use PDF\Graphics\ColourSpaces\Pattern;
use PDF\Graphics\ColourSpaces\Separation;
use PDF\Graphics\ColourSpaces\TintTransform;
use PDF\Graphics\ColourSpaces\WhitePoint;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\Patterns\PaintType;
use PDF\Graphics\Patterns\PatternColour;
use PDF\Graphics\Patterns\ShadingPattern;
use PDF\Graphics\Patterns\Shadings\AxialShading;
use PDF\Graphics\Patterns\Shadings\Circle;
use PDF\Graphics\Patterns\Shadings\CoonsPatch;
use PDF\Graphics\Patterns\Shadings\CoonsPatchShading;
use PDF\Graphics\Patterns\Shadings\DecodeInterval;
use PDF\Graphics\Patterns\Shadings\FreeFormGouraudShading;
use PDF\Graphics\Patterns\Shadings\FreeFormMesh;
use PDF\Graphics\Patterns\Shadings\FreeFormTriangle;
use PDF\Graphics\Patterns\Shadings\FunctionBasedShading;
use PDF\Graphics\Patterns\Shadings\LatticeGouraudShading;
use PDF\Graphics\Patterns\Shadings\LatticeMesh;
use PDF\Graphics\Patterns\Shadings\MeshDecode;
use PDF\Graphics\Patterns\Shadings\MeshFormat;
use PDF\Graphics\Patterns\Shadings\MeshVertex;
use PDF\Graphics\Patterns\Shadings\PatchColours;
use PDF\Graphics\Patterns\Shadings\PatchControlPoints;
use PDF\Graphics\Patterns\Shadings\PatchMesh;
use PDF\Graphics\Patterns\Shadings\Point;
use PDF\Graphics\Patterns\Shadings\RadialShading;
use PDF\Graphics\Patterns\Shadings\ShadingFunctions;
use PDF\Graphics\Patterns\Shadings\ShadingOptions;
use PDF\Graphics\Patterns\Shadings\TensorProductPatch;
use PDF\Graphics\Patterns\Shadings\TensorProductPatchShading;
use PDF\Graphics\Patterns\TilingPattern;
use PDF\Graphics\Patterns\TilingType;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\PaintShading;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColorSpace;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColorWithPattern;
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

// Local functions only abbreviate numeric values in this example.
// Color spaces, PDF functions, geometries and resources remain explicit
// objects: no drawing engine chooses their construction.
$n = static fn (int|float $value): NumericType => new NumericType($value);
$point = static fn (int|float $x, int|float $y): Point => new Point($n($x), $n($y));
$vector = static fn (int|float ...$values): NumberArray => new NumberArray(...array_map($n, $values));
$unit = new NumberInterval($n(0), $n(1));
$white = new WhitePoint($n(0.9505), $n(1), $n(1.089));
$rgb = new DeviceColourSpace(ColourSpaceFamily::DeviceRGB);
$gray = new CalGray($white, $n(2.2));
$calRgb = new CalRGB($white);
$lab = new Lab($white);

// The ICC profile comes from an external producer, here the sRGB fixture
// documented in tests/Fixtures/graphics/srgb.json. ICCProfile retains its bytes;
// PDF readers perform ICC management. The core does not create the profile.
$iccBytes = file_get_contents(dirname(__DIR__) . '/tests/Fixtures/graphics/srgb.icc');
if ($iccBytes === false) {
    throw new RuntimeException('The supplied sRGB test profile is unavailable.');
}
$icc = new ICCBased(new ICCProfile($iccBytes, new IntegerType(3), $rgb, filters: new FlateDecode()));
$indexed = new Indexed($rgb, new IntegerType(2), new HexadecimalString("\xEF\x52\x52\x4F\xA7\x86\x42\x75\xCF"));

// A spot-color name is accompanied by an actual alternate-color function.
// Here white becomes orange as the tint value changes from 0 to 1.
$orange = new ExponentialInterpolationFunction(new Domain($unit), $n(1), $vector(1, 1, 1), $vector(1, 0.35, 0.05), new Range($unit, $unit, $unit));
$separation = new Separation(new NameType('Orange'), new TintTransform($orange, 1, $rgb));

// This function has two inputs and three outputs: (x, y, 1-x).
// Calculator-language operators are also validated objects.
$plane = new PostScriptCalculatorFunction(new Domain($unit, $unit), new Range($unit, $unit, $unit), new Procedure(new Number($n(1)), new Number($n(2)), CalculatorOperator::Index, CalculatorOperator::Subtract));
$deviceN = new DeviceN(new ColourComponentNames(new NameType('MixA'), new NameType('MixB')), new TintTransform($plane, 2, $rgb));

$gradient = new ShadingFunctions(new ExponentialInterpolationFunction(new Domain($unit), $n(1), $vector(0.94, 0.2, 0.3), $vector(0.2, 0.4, 0.94), new Range($unit, $unit, $unit)));
$unitBox = new Rectangle($n(0), $n(0), $n(1), $n(1));
$bounded = new ShadingOptions(boundingBox: $unitBox);
$axial = new AxialShading($rgb, $point(0, 0), $point(1, 0), $gradient, options: $bounded);
$decodeUnit = new DecodeInterval($n(0), $n(1));
$meshFormat = new MeshFormat(new MeshDecode($decodeUnit, $decodeUnit, $decodeUnit, $decodeUnit, $decodeUnit));
$red = $vector(1, 0.18, 0.28);
$green = $vector(0.1, 0.8, 0.55);
$blue = $vector(0.2, 0.4, 1);
$yellow = $vector(1, 0.8, 0.15);
$vertex = static fn (int|float $x, int|float $y, NumberArray $colour): MeshVertex => new MeshVertex($point($x, $y), $colour);

// Points follow Figures 30 and 33 of the specification, around the boundary,
// followed by the four interior points of the tensor-product patch.
$boundary = [$point(0, 0), $point(0, 1 / 3), $point(0, 2 / 3), $point(0, 1), $point(1 / 3, 1), $point(2 / 3, 1), $point(1, 1), $point(1, 2 / 3), $point(1, 1 / 3), $point(1, 0), $point(2 / 3, 0), $point(1 / 3, 0)];
$corners = new PatchColours($red, $green, $blue, $yellow);
$shadings = [
    new FunctionBasedShading($rgb, new ShadingFunctions($plane), options: $bounded),
    $axial,
    new RadialShading($rgb, new Circle($point(0.5, 0.5), $n(0)), new Circle($point(0.5, 0.5), $n(0.7)), $gradient, options: $bounded),
    new FreeFormGouraudShading($rgb, new FreeFormMesh(new FreeFormTriangle($vertex(0, 0, $red), $vertex(1, 0, $yellow), $vertex(0, 1, $blue)), new FreeFormTriangle($vertex(1, 0, $yellow), $vertex(1, 1, $green), $vertex(0, 1, $blue))), $meshFormat, options: $bounded),
    new LatticeGouraudShading($rgb, new LatticeMesh(new IntegerType(2), $vertex(0, 0, $red), $vertex(1, 0, $yellow), $vertex(0, 1, $blue), $vertex(1, 1, $green)), $meshFormat, options: $bounded),
    new CoonsPatchShading($rgb, new PatchMesh(new CoonsPatch(new PatchControlPoints(...$boundary), $corners)), $meshFormat, options: $bounded),
    new TensorProductPatchShading($rgb, new PatchMesh(new TensorProductPatch(new PatchControlPoints(...[...$boundary, $point(0.6, 1 / 3), $point(1 / 3, 2 / 3), $point(2 / 3, 2 / 3), $point(2 / 3, 1 / 3)]), $corners)), $meshFormat, options: $bounded),
];

// A colored cell retains its own colors. A stencil cell contains none:
// its color is supplied every time it is selected.
$cellBox = new Rectangle($n(0), $n(0), $n(12), $n(12));
$stencil = new TilingPattern(new ContentStreams(new AppendRectangle($n(1), $n(1), $n(5), $n(5)), new FillPathNonzero()), PaintType::Uncoloured, TilingType::NoDistortion, $cellBox, $n(12), $n(12), new ResourceDictionary());
$coloured = new TilingPattern(new ContentStreams(new SetNonstrokingRGBColor($n(0.2), $n(0.4), $n(0.8)), new AppendRectangle($n(1), $n(1), $n(5), $n(10)), new FillPathNonzero(), new SetNonstrokingRGBColor($n(0.9), $n(0.6), $n(0.1)), new AppendRectangle($n(7), $n(1), $n(4), $n(10)), new FillPathNonzero()), PaintType::Coloured, TilingType::ConstantSpacing, $cellBox, $n(12), $n(12), new ResourceDictionary());
$patternSpace = new Pattern();
$stencilSpace = new Pattern($rgb);
$patterns = [
    new PatternColour($patternSpace, new NameType('ColouredTile'), $coloured),
    new PatternColour($stencilSpace, new NameType('StencilTile'), $stencil, new ColourComponents($rgb, $n(0.7), $n(0.2), $n(0.7))),
    new PatternColour($patternSpace, new NameType('GradientTile'), new ShadingPattern($axial, new Matrix($n(152), $n(0), $n(0), $n(70), $n(394), $n(192)))),
];
$spaces = ['CalGray' => $gray, 'CalRGB' => $calRgb, 'Lab' => $lab, 'ICCBased' => $icc, 'Indexed' => $indexed, 'Separation' => $separation, 'DeviceN' => $deviceN, 'Pattern' => $patternSpace, 'Stencil' => $stencilSpace];
$resources = new ResourceDictionary(
    new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))))),
    new Entry(new NameType('ColorSpace'), new DictionaryType(...array_map(static fn (string $name): Entry => new Entry(new NameType($name), $spaces[$name]->value()), array_keys($spaces)))),
    new Entry(new NameType('Shading'), new DictionaryType(...array_map(static fn (int $index): Entry => new Entry(new NameType('Sh' . ($index + 1)), new IndirectReference($shadings[$index])), array_keys($shadings)))),
    new Entry(new NameType('Pattern'), new DictionaryType(...array_map(static fn (PatternColour $colour): Entry => new Entry($colour->resourceName, new IndirectReference($colour->pattern)), $patterns))),
);
/** @return list<Operator> */
$text = static fn (string $label, int $x, int $y, int $size = 11): array => [new SetNonstrokingRGBColor($n(0.12), $n(0.16), $n(0.22)), new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), $n($size)), new MoveTextPosition($n($x), $n($y)), new ShowText(new LiteralString($label)), new EndTextObject()];
$pageOne = [...$text('Couleurs et motifs PDF', 42, 742, 25), ...$text('Les espaces, fonctions et ressources sont construits explicitement.', 42, 712)];
$swatches = [new ColourComponents($gray, $n(0.6)), new ColourComponents($calRgb, $n(0.35), $n(0.6), $n(0.9)), new ColourComponents($lab, $n(70), $n(40), $n(50)), new ColourComponents($icc, $n(0.3), $n(0.65), $n(0.85)), new ColourComponents($indexed, $n(1)), new ColourComponents($separation, $n(0.85)), new ColourComponents($deviceN, $n(0.25), $n(0.7))];
foreach ($swatches as $index => $colour) {
    $name = array_keys($spaces)[$index];
    $x = 42 + ($index % 3) * 176;
    $y = 594 - intdiv($index, 3) * 126;
    $select = in_array($colour->space->family(), [ColourSpaceFamily::ICCBased, ColourSpaceFamily::Separation, ColourSpaceFamily::DeviceN], true) ? new SetNonstrokingColorWithPattern(...$colour->numbers) : new SetNonstrokingColor(...$colour->numbers);
    array_push($pageOne, new SaveGraphicsState(), new SetNonstrokingColorSpace(new NameType($name)), $select, new AppendRectangle($n($x), $n($y), $n(152), $n(80)), new FillPathNonzero(), new RestoreGraphicsState(), ...$text($name, $x, $y - 18));
}
array_push($pageOne, ...$text('Motifs : cellule coloree, stencil, puis shading', 42, 285, 15));
foreach ($patterns as $index => $colour) {
    $x = 42 + $index * 176;
    $spaceName = $index === 1 ? 'Stencil' : 'Pattern';
    // The local matrix positions the rectangle. The GradientTile matrix expresses
    // its position in the PDF pattern's initial user space.
    array_push($pageOne, new SaveGraphicsState(), new ConcatenateMatrix(new Matrix($n(1), $n(0), $n(0), $n(1), $n($x), $n(192))), new SetNonstrokingColorSpace(new NameType($spaceName)), $colour->nonstroking(), new AppendRectangle($n(0), $n(0), $n(152), $n(70)), new FillPathNonzero(), new RestoreGraphicsState(), ...$text($colour->resourceName->value, $x, 174));
}
array_push($pageOne, ...$text('ICC : profil fourni. Teintes : alternatives PDF explicites.', 42, 103), ...$text('ISO 32000-1, 8.6 / 8.7 - les calculs PDF sont preserves.', 42, 82));

$pageTwo = [...$text('Les sept types de shading', 42, 742, 25), ...$text('Chaque apercu utilise un BBox local et une matrice de placement.', 42, 712)];
$labels = ['1 - Fonction 2D', '2 - Axial', '3 - Radial', '4 - Triangles libres', '5 - Lattice de triangles', '6 - Patch Coons', '7 - Patch tensoriel'];
foreach ($shadings as $index => $shading) {
    $x = 42 + ($index % 3) * 176;
    $y = 532 - intdiv($index, 3) * 184;
    array_push($pageTwo, new SaveGraphicsState(), new ConcatenateMatrix(new Matrix($n(152), $n(0), $n(0), $n(140), $n($x), $n($y))), new PaintShading(new NameType('Sh' . ($index + 1))), new RestoreGraphicsState(), ...$text($labels[$index], $x, $y - 20));
}
array_push($pageTwo, ...$text('Types 4-7 : les octets du mesh proviennent des objets geometriques.', 42, 105), ...$text('Points, couleurs, Decode, profondeurs et reutilisation restent explicites.', 42, 84));
$pageBox = new Rectangle($n(0), $n(0), $n(612), $n(792));
$pages = (new PageTreeNode())->addKid(new PageObject($pageBox, $resources, new StreamObject(new ContentStreams(...$pageOne))))->addKid(new PageObject($pageBox, $resources, new StreamObject(new ContentStreams(...$pageTwo))));
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/colours-patterns.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
