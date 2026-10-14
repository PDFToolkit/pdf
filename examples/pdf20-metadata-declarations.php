<?php

declare(strict_types=1);

use PDF\Document\Files as Files;
use PDF\Document\Metadata as Metadata;
use PDF\Document\Metadata\Declarations as Declarations;
use PDF\Document\Parts as Parts;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources;
use PDF\FileStructure;
use PDF\Graphics\ColourSpaces\CalRGB;
use PDF\Graphics\ColourSpaces\WhitePoint;
use PDF\Graphics\GraphicsState\BlackPointCompensation;
use PDF\Graphics\GraphicsState\GraphicsStateParameters;
use PDF\Graphics\GraphicsState\UseBlackPointCompensation;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
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
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// PDF Declarations are explicit author assertions. This demonstration identifier
// asserts a local review workflow; it does not claim PDF/A, PDF/UA or WTPDF conformance.
$review = new Declarations\Declarations(new Declarations\Declaration(
    new Metadata\MetadataUri('https://example.org/pdf/reviewed'),
    new Declarations\ClaimData(new Metadata\XmlText('PDFToolkit example'), new Declarations\ClaimDate('2026-10-14'), claimReport: new Declarations\ClaimReport('#ef=review.txt')),
));
$title = new Metadata\XmpLanguageAlternative(new Metadata\XmpName('dc', new Metadata\MetadataUri('http://purl.org/dc/elements/1.1/'), 'title'), new Metadata\XmlText('PDF 2.0 metadata and document parts'), new Metadata\XmpLocalizedText('fr', new Metadata\XmlText('Métadonnées PDF 2.0 et parties de document')));
$documentMetadata = new Metadata\MetadataStream(new Metadata\XmpPacket($title, $review));
// Object-level declarations remain separate: the catalog claim does not extend
// to this embedded report. Names/EmbeddedFiles makes the report easy to discover.
$report = new Files\EmbeddedFileStream("Example review report. This is not a certification.\n", new NameType('text/plain'), modificationDate: new Date(new DateTimeImmutable('2026-10-14T00:00:00Z')), filters: new FlateDecode());
$reportFile = new Files\FileSpecification(new LiteralString('review.txt'), new Files\FileSpecificationOptions(embedded: new Files\EmbeddedFileBindings(new Files\EmbeddedFileBinding(Files\EmbeddedFileKey::File, $report))), new Files\AssociatedFileRelationship(Files\AFRelationship::Supplement));
$attachments = new Files\EmbeddedFiles(new Files\EmbeddedFileAttachment(new LiteralString('review.txt'), $reportFile));
$associated = new Files\AssociatedFiles(new IndirectReference($reportFile));

// DPM is a typed PDF dictionary describing a workflow. Metadata is a different,
// XMP stream entry. Both may coexist on the same document-part node.
$part = new Parts\DocumentPart(new Parts\DocumentPartMetadata(new Entry(new NameType('Job'), new TextString('Proof 42'))), new Metadata\MetadataEntry(new Metadata\MetadataStream(new Metadata\XmpPacket(new Metadata\XmpTextProperty(new Metadata\XmpName('job', new Metadata\MetadataUri('urn:example:job'), 'description'), new Metadata\XmlText('One-page proof'))))), new Files\AssociatedFilesEntry($associated));
$partRoot = new Parts\DocumentPartRoot($part, new IntegerType(0), new NameType('Proof'));
$sequenceMetadata = new Metadata\MetadataStream(new Metadata\XmpPacket(new Metadata\XmpTextProperty(new Metadata\XmpName('sample', new Metadata\MetadataUri('urn:example:sample'), 'purpose'), new Metadata\XmlText('Calibrated colour demonstration'))));
$state = new GraphicsStateParameters(new UseBlackPointCompensation(BlackPointCompensation::On));
// PDF 2.0 requires explicit metrics and a descriptor even for standard fonts.
$font = require __DIR__ . '/fixtures/pdf20-courier-font.php';
$resources = new ResourceDictionary(
    new Resources\Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))),
    new Resources\ExtGState(new DictionaryType(new Entry(new NameType('BpcOn'), new IndirectReference($state)))),
    new Resources\ColorSpace(new DictionaryType(new Entry(new NameType('Calibrated'), new CalRGB(new WhitePoint(new NumericType(0.9505), new NumericType(1), new NumericType(1.089)))))),
    new Resources\Properties(new DictionaryType(new Entry(new NameType('ColourInfo'), new Metadata\MetadataPropertyList($sequenceMetadata)))),
);
$operators = [];
foreach (['PDF 2.0: metadata and document parts', 'Catalog XMP: a bilingual title and explicit review declaration.', 'A named property list attaches XMP to this colour sample.', 'The page belongs to one DPart with separate DPM and XMP.', 'The review report is an associated file and a named attachment.', 'BPC is ON for the calibrated colour conversion; its display', 'depends on the PDF processor and its output colour profile.'] as $index => $line) {
    $operators[] = new Op\BeginTextObject();
    $operators[] = new Op\SetTextFontAndSize(new NameType('F1'), new NumericType($index === 0 ? 20 : 11));
    $operators[] = new Op\MoveTextPosition(new NumericType(48), new NumericType(780 - 30 * $index));
    $operators[] = new Op\ShowText(new LiteralString($line));
    $operators[] = new Op\EndTextObject();
}
$operators = [...$operators, new Op\SaveGraphicsState(), new Op\BeginMarkedContentWithProperties(new NameType('Sample'), new NameType('ColourInfo')), new Op\SetGraphicsStateParameters(new NameType('BpcOn')), new Op\SetNonstrokingColorSpace(new NameType('Calibrated')), new Op\SetNonstrokingColor(new NumericType(0.1), new NumericType(0.3), new NumericType(0.6)), new Op\AppendRectangle(new NumericType(48), new NumericType(440), new NumericType(496), new NumericType(100)), new Op\FillPathNonzero(), new Op\EndMarkedContent(), new Op\RestoreGraphicsState()];
$page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)), $resources, new StreamObject(new ContentStreams(...$operators), filters: new FlateDecode()), new Parts\DocumentPartEntry($part));
$pages = (new PageTreeNode())->addKid($page);
$part->setPageRange($page);
$catalog = new DocumentCatalog($pages, new Metadata\MetadataEntry($documentMetadata), $partRoot->catalogEntry(), new Files\AssociatedFilesEntry($associated), new Entry(new NameType('Names'), new DictionaryType($attachments->nameDictionaryEntry())));
$output = $argv[1] ?? __DIR__ . '/pdf20-metadata-declarations.pdf';
(new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->writeTo($output);
echo $output . PHP_EOL;
