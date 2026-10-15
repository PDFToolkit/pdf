<?php

declare(strict_types=1);

use PDF\Document\Fonts\Encodings\EncodingDifference;
use PDF\Document\Fonts\Encodings\EncodingDifferences;
use PDF\Document\Fonts\Encodings\EncodingDictionary;
use PDF\Document\Fonts\SimpleWidths;
use PDF\Document\Fonts\Type3CharProc;
use PDF\Document\Fonts\Type3CharProcs;
use PDF\Document\Fonts\Type3Font;
use PDF\Document\Fonts\Type3Glyph;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\XObjects\FormXObject;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$directory = $argv[1] ?? throw new InvalidArgumentException('Supply an existing output directory.');
if (!is_dir($directory)) {
    throw new InvalidArgumentException('The output directory must already exist.');
}
$box = new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(1000), new IntegerType(1000));
$colours = [];
foreach (['green' => [0, 1, 0], 'red' => [1, 0, 0], 'blue' => [0, 0, 1], 'yellow' => [1, 1, 0]] as $name => [$red, $green, $blue]) {
    $form = new FormXObject(new ContentStreams(new Op\SetNonstrokingRGBColor(new IntegerType($red), new IntegerType($green), new IntegerType($blue)), new Op\AppendRectangle(new IntegerType(0), new IntegerType(0), new IntegerType(1000), new IntegerType(1000)), new Op\FillPathNonzero()), $box, new ResourceDictionary());
    $colours[$name] = new ResourceDictionary(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Ink'), new IndirectReference($form)))));
}
foreach (['glyph', 'font', 'page', 'inherited-page', 'nested-form'] as $case) {
    $glyph = new Type3Glyph(new Type3GlyphContentStream(new Op\SetGlyphWidth(new IntegerType(1000), new IntegerType(0)), new Op\InvokeXObject(new NameType('Ink'))), $case === 'glyph' ? $colours['green'] : null);
    $font = new Type3Font($box, new Matrix(a: new NumericType(0.001), d: new NumericType(0.001)), new Type3CharProcs(new Type3CharProc(new NameType('A'), $glyph)), new EncodingDictionary(new EncodingDifferences(new EncodingDifference(new IntegerType(65), new NameType('A')))), new SimpleWidths(new IntegerType(65), new IntegerType(1000)), in_array($case, ['glyph', 'font'], true) ? $colours['red'] : null);
    $fontEntry = new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('Outer'), new IndirectReference($font))));
    $pageResources = new ResourceDictionary(...[...iterator_to_array($colours['blue']->entries(), false), $fontEntry]);
    $content = new ContentStreams(new Op\BeginTextObject(), new Op\SetTextFontAndSize(new NameType('Outer'), new IntegerType(100)), new Op\MoveTextPosition(new IntegerType(50), new IntegerType(50)), new Op\ShowText(new LiteralString('A')), new Op\EndTextObject());
    if ($case === 'nested-form') {
        $formResources = new ResourceDictionary(...[...iterator_to_array($colours['yellow']->entries(), false), $fontEntry]);
        $form = new FormXObject($content, $box, $formResources);
        $xobjects = $pageResources->get('XObject')->with(new Entry(new NameType('Nested'), new IndirectReference($form)));
        $pageResources = new ResourceDictionary($fontEntry, new Entry(new NameType('XObject'), $xobjects));
        $content = new ContentStreams(new Op\InvokeXObject(new NameType('Nested')));
    }
    $inherited = in_array($case, ['inherited-page', 'nested-form'], true);
    $pages = new PageTreeNode(...($inherited ? [new Entry(new NameType('Resources'), new IndirectReference($pageResources))] : []));
    $pages->addKid(new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), $inherited ? null : $pageResources, new StreamObject($content)));
    (new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($pages))))->writeTo($directory . '/' . $case . '.pdf');
}
echo "Five Type3 resource-priority PDF2 fixtures written.\n";
