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
use PDF\Document\Structure\WtPdfFindingStatus;
use PDF\Document\Structure\WtPdfFontUsageChecks;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Matrix;
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
use PDF\Text\TextRenderingMode;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = $argv[1] ?? throw new InvalidArgumentException('Supply an existing output directory.');
if (!is_dir($directory)) {
    throw new InvalidArgumentException('The output directory must already exist.');
}

// Real PDF font dictionaries and CharProcs, deliberately without a WTPDF claim.
// A draws green and renders an unembedded Courier glyph; B draws only blue.
// The unused A program and its font remain in every file to distinguish actual
// use from a resource inventory. This is a counterexample, not a font certificate.
$auxiliary = require dirname(__DIR__, 2) . '/examples/fixtures/pdf20-courier-font.php';
$glyphA = new Type3Glyph(new Type3GlyphContentStream(
    new Op\SetGlyphWidth(new IntegerType(1000), new IntegerType(0)),
    new Op\SetNonstrokingRGBColor(new IntegerType(0), new IntegerType(1), new IntegerType(0)),
    new Op\AppendRectangle(new IntegerType(0), new IntegerType(0), new IntegerType(1000), new IntegerType(1000)),
    new Op\FillPathNonzero(),
    new Op\SetNonstrokingGray(new IntegerType(0)),
    new Op\BeginTextObject(),
    new Op\SetTextFontAndSize(new NameType('Aux'), new IntegerType(200)),
    new Op\SetTextRenderingMode(TextRenderingMode::Fill),
    new Op\MoveTextPosition(new IntegerType(100), new IntegerType(100)),
    new Op\ShowText(new LiteralString('x')),
    new Op\EndTextObject(),
));
$glyphB = new Type3Glyph(new Type3GlyphContentStream(
    new Op\SetGlyphWidth(new IntegerType(1000), new IntegerType(0)),
    new Op\SetNonstrokingRGBColor(new IntegerType(0), new IntegerType(0), new IntegerType(1)),
    new Op\AppendRectangle(new IntegerType(0), new IntegerType(0), new IntegerType(1000), new IntegerType(1000)),
    new Op\FillPathNonzero(),
));
$font = new Type3Font(
    new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(1000), new IntegerType(1000)),
    new Matrix(a: new NumericType(0.001), d: new NumericType(0.001)),
    new Type3CharProcs(new Type3CharProc(new NameType('A'), $glyphA), new Type3CharProc(new NameType('B'), $glyphB)),
    new EncodingDictionary(new EncodingDifferences(new EncodingDifference(new IntegerType(65), new NameType('A'), new NameType('B')))),
    new SimpleWidths(new IntegerType(65), new IntegerType(1000), new IntegerType(1000)),
    new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('Aux'), new IndirectReference($auxiliary))))),
);
$results = [];
foreach ([
    ['a-visible', 'A', 0],
    ['b-visible', 'B', 0],
    ['a-invisible', 'A', 3],
    ['a-clip-only', 'A', 7],
    ['empty', '', 0],
] as [$name, $text, $mode]) {
    $resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('Outer'), new IndirectReference($font)))));
    $content = new StreamObject(new ContentStreams(
        new Op\BeginTextObject(),
        new Op\SetTextFontAndSize(new NameType('Outer'), new IntegerType(100)),
        new Op\SetTextRenderingMode(TextRenderingMode::from($mode)),
        new Op\MoveTextPosition(new IntegerType(50), new IntegerType(50)),
        new Op\ShowText(new LiteralString($text)),
        new Op\EndTextObject(),
    ));
    $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), $resources, $content);
    $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
    $findings = (new WtPdfFontUsageChecks())->inspect($catalog);
    $failures = array_values(array_filter($findings, static fn ($finding): bool => $finding->status === WtPdfFindingStatus::Failed));
    $file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog));
    $file->writeTo($directory . '/' . $name . '.pdf');
    $results[$name] = [
        'textHex' => bin2hex($text),
        'mode' => $mode,
        'failureClauses' => array_map(static fn ($finding): string => $finding->clause, $failures),
        'pending' => array_map(static fn ($finding): string => $finding->message, array_values(array_filter($findings, static fn ($finding): bool => $finding->status !== WtPdfFindingStatus::Failed))),
    ];
}
file_put_contents($directory . '/native-findings.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo json_encode(['pdfs' => count($results), 'output' => $directory], JSON_THROW_ON_ERROR) . PHP_EOL;
