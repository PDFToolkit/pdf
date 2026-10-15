<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Redaction\RedactionAnnotation;
use PDF\Document\Redaction\RedactionPageRequest;
use PDF\Document\Redaction\RedactionPlan;
use PDF\Document\Redaction\SecureRedaction;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
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

// Adobe redaction addendum, physical pages 2–4. An annotation merely identifies
// a region. SecureRedaction removes source instructions and affected payloads,
// then builds a new file. Never append this result to an earlier PDF revision.
$font = new Type1Font(StandardFont::Courier);
$image = new StreamObject('SECRETIMG', new DictionaryType(
    new Entry(new NameType('Type'), new NameType('XObject')),
    new Entry(new NameType('Subtype'), new NameType('Image')),
    new Entry(new NameType('Width'), new IntegerType(3)),
    new Entry(new NameType('Height'), new IntegerType(1)),
    new Entry(new NameType('ColorSpace'), new NameType('DeviceRGB')),
    new Entry(new NameType('BitsPerComponent'), new IntegerType(8)),
));
$resources = new ResourceDictionary(
    new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))),
    new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Im1'), new IndirectReference($image)))),
);
$box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(120));
$first = new PageObject($box, $resources, new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(10)),
    new SetTextMatrix(new Matrix(e: new NumericType(10), f: new NumericType(100))),
    new ShowText(new LiteralString('PUBLIC PAGE 1')),
    new EndTextObject(),
    new SaveGraphicsState(),
    new ConcatenateMatrix(new Matrix(new NumericType(10), d: new NumericType(10), e: new NumericType(20), f: new NumericType(20))),
    new InvokeXObject(new NameType('Im1')),
    new RestoreGraphicsState(),
    new SetNonstrokingRGBColor(new NumericType(0), new NumericType(1), new NumericType(0)),
    new AppendRectangle(new NumericType(100), new NumericType(5), new NumericType(20), new NumericType(10)),
    new FillPathNonzero(),
)));
$annotation = new DictionaryType(
    new Entry(new NameType('Type'), new NameType('Annot')),
    new Entry(new NameType('Subtype'), new NameType('Redact')),
    new Entry(new NameType('Rect'), new Rectangle(new NumericType(19), new NumericType(68), new NumericType(81), new NumericType(92))),
    new Entry(new NameType('IC'), new ArrayType(new NumericType(0), new NumericType(0), new NumericType(0))),
    new Entry(new NameType('OverlayText'), new TextString('REDACTED')),
    new Entry(new NameType('DA'), new DefaultAppearance(new SetTextFontAndSize(new NameType('F1'), new NumericType(8)), new SetNonstrokingRGBColor(new NumericType(1), new NumericType(1), new NumericType(1)))),
    new Entry(new NameType('Repeat'), new BooleanType(true)),
    new Entry(new NameType('Q'), new IntegerType(1)),
);
$second = new PageObject($box, $resources, new StreamObject(new ContentStreams(
    new BeginMarkedContentWithProperties(new NameType('Span'), new DictionaryType(new Entry(new NameType('ActualText'), new TextString('SECRET-ACTUALTEXT')))),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(10)),
    new SetTextMatrix(new Matrix(e: new NumericType(20), f: new NumericType(75))),
    new ShowText(new LiteralString('SECRET-TEXT')),
    new EndTextObject(),
    new EndMarkedContent(),
    new SaveGraphicsState(),
    new ConcatenateMatrix(new Matrix(new NumericType(10), d: new NumericType(10), e: new NumericType(20), f: new NumericType(70))),
    new InvokeXObject(new NameType('Im1')),
    new RestoreGraphicsState(),
    new SetNonstrokingRGBColor(new NumericType(0), new NumericType(1), new NumericType(0)),
    new AppendRectangle(new NumericType(100), new NumericType(5), new NumericType(20), new NumericType(10)),
    new FillPathNonzero(),
    // A deliberately persistent source CTM must not move the replacement mark.
    new ConcatenateMatrix(new Matrix(e: new NumericType(5000))),
)), new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($annotation))));
$pages = (new PageTreeNode())->addKid($first)->addKid($second);
$catalog = new DocumentCatalog($pages, new Entry(new NameType('Metadata'), new IndirectReference(new StreamObject('<x:xmpmeta xmlns:x="adobe:ns:meta/">SECRET-XMP</x:xmpmeta>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML')))))));

// There is no font-program geometry backend in this example: all BT/ET text
// objects on the selected page are removed conservatively. Text on untouched
// pages remains. A tainted shared image is removed from every reachable use.
$result = (new SecureRedaction())->apply($catalog, new RedactionPlan(new RedactionPageRequest($second, new RedactionAnnotation($annotation))));
$path = $argv[1] ?? __DIR__ . '/pdf17-secure-redaction.pdf';
$result->writeTo($path);
// Independent validators can retain the original separately; it is never
// embedded, copied or incrementally appended to the redacted result.
if (isset($argv[2]) && str_starts_with($argv[2], '--source=')) {
    (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)))->writeTo(substr($argv[2], strlen('--source=')));
}
echo $path . PHP_EOL;
