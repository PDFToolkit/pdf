<?php

declare(strict_types=1);

use PDF\Document\Barcodes\BarcodeDataPreparation;
use PDF\Document\Barcodes\BarcodeEncodingRequest;
use PDF\Document\Barcodes\BarcodeFieldPreparation;
use PDF\Document\Barcodes\BarcodeImageEncoder;
use PDF\Document\Barcodes\BarcodeLimits;
use PDF\Document\Barcodes\BarcodeWidgetParameters;
use PDF\Document\Extensions\DeveloperExtension;
use PDF\Document\Extensions\DeveloperExtensionEntry;
use PDF\Document\Extensions\DeveloperExtensions;
use PDF\Document\Extensions\DeveloperPrefix;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\BarcodeField;
use PDF\Document\Forms\BarcodePaperMetadata;
use PDF\Document\Forms\BarcodeSymbology;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\SampleLayout;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Graphics\XObjects\ImageXObject;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Utf16BETextStringEncoding;
use PDF\Syntax\DocumentStructure\CatalogDictionary\AcroForm as AcroFormEntry;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Extensions;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = $argv[1] ?? throw new InvalidArgumentException('Supply an existing fixture output directory.');
if (!is_dir($directory)) {
    throw new InvalidArgumentException('The fixture output directory must already exist.');
}

// These patterns only verify native PDF appearance placement. They encode NO symbology.
$cases = [
    ['multi-direct', 'abcdefgh', 0, 3, false, false],
    ['multi-flate', "été\0documents", 1, 3, false, true],
    ['single-separate', 'one', 0, 1, false, false],
    ['single-merged', 'one', 0, 1, true, false],
    ['empty-value', '', 0, 2, false, false],
];
$results = [];
foreach ($cases as [$name, $text, $dataPrep, $count, $merged, $unicode]) {
    $value = $unicode ? new TextString($text, new Utf16BETextStringEncoding()) : new TextString($text);
    $field = new BarcodeField(new PartialFieldName(new TextString('barcode')), new BarcodeDataPreparation(new IntegerType($dataPrep)), $value);
    $parameters = [];
    for ($index = 0; $index < $count; ++$index) {
        $left = 10 + $index * 195;
        $rectangle = $index === 1
            ? new Rectangle(new NumericType($left + 180), new NumericType(120), new NumericType($left), new NumericType(30))
            : new Rectangle(new NumericType($left), new NumericType(30), new NumericType($left + 180), new NumericType(120));
        $symbology = $index === 1 ? BarcodeSymbology::DataMatrix : BarcodeSymbology::QrCode;
        $metadata = new BarcodePaperMetadata($symbology, new NumericType(2.5), new NumericType(1.25), new IntegerType(1), errorCorrection: $symbology === BarcodeSymbology::QrCode ? new IntegerType(0) : null, entries: new Entry(new NameType('Caption'), new TextString('TEST PATTERN - NO SYMBOLOGY ENCODER')));
        $parameters[] = new BarcodeWidgetParameters($rectangle, $metadata);
    }
    $encoder = new class () implements BarcodeImageEncoder {
        /** @var list<array{index:int,total:int,offset:int,bytes:string,symbology:string}> */
        public array $calls = [];

        public function encode(BarcodeEncodingRequest $request): ImageXObject
        {
            $this->calls[] = ['index' => $request->message->index, 'total' => $request->message->total, 'offset' => $request->message->offset, 'bytes' => base64_encode($request->message->value->value), 'symbology' => $request->widget->metadata->get('Symbology')->value];
            $pattern = $request->message->index % 2 === 0 ? "\0\xff\xff\0" : "\xff\0\0\xff";
            return new ImageXObject(new ImageSamples(new ImageDimensions(2, 2), new SampleLayout(DeviceColorSpace::Gray), $pattern));
        }
    };
    $rendered = (new BarcodeFieldPreparation($field, new BarcodeLimits(), ...$parameters))->render($encoder);
    foreach ($rendered as $rendering) {
        if ($merged) {
            $field->mergeWidget($rendering->widget);
        } else {
            $field->addWidget($rendering->widget);
        }
    }
    $font = new Type1Font(StandardFont::Helvetica);
    $resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('Helv'), new IndirectReference($font)))));
    $tree = (new FieldTree(new DefaultAppearance(new SetTextFontAndSize(new NameType('Helv'), new NumericType(10))), $resources))->addRoot($field);
    $background = new StreamObject(new ContentStreams(
        new SetNonstrokingRGBColor(new NumericType(0.25), new NumericType(0.5), new NumericType(0.75)),
        new AppendRectangle(new NumericType(0), new NumericType(0), new NumericType(600), new NumericType(160)),
        new FillPathNonzero(),
    ));
    $annotations = array_map(static fn ($rendering) => new IndirectReference($merged ? $field : $rendering->widget), $rendered);
    $page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(600), new NumericType(160)), new ResourceDictionary(), $background, new Entry(new NameType('Annots'), new ArrayType(...$annotations)));
    foreach ($rendered as $rendering) {
        $rendering->widget->bindToPage($page);
    }
    $pages = (new PageTreeNode())->addKid($page);
    $extension = new DeveloperExtensions(new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('ADBE')), new DeveloperExtension(new NameType('1.7'), new IntegerType(3))));
    $catalog = new DocumentCatalog($pages, new AcroFormEntry(new IndirectReference(new AcroForm($tree))), new Extensions($extension));
    (new FileStructure(new FileHeader('1.7'), new FileBody($catalog)))->writeTo($directory . '/' . $name . '.pdf');
    $results[] = ['name' => $name, 'merged' => $merged, 'calls' => $encoder->calls];
}
file_put_contents($directory . '/preparation.json', json_encode(['symbologyProof' => false, 'cases' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
echo "Wrote five native barcode-appearance fixtures using declared non-barcode test patterns.\n";
