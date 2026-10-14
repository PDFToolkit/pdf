<?php

declare(strict_types=1);

use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\CIDToGIDMap;
use PDF\Document\Fonts\CIDWidths;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\FontFile2;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\Type0Font;
use PDF\Document\Metadata\Declarations\Declaration;
use PDF\Document\Metadata\Declarations\Declarations;
use PDF\Document\Metadata\MetadataEntry;
use PDF\Document\Metadata\MetadataStream;
use PDF\Document\Metadata\MetadataUri;
use PDF\Document\Metadata\XmlText;
use PDF\Document\Metadata\XmpLanguageAlternative;
use PDF\Document\Metadata\XmpLocalizedText;
use PDF\Document\Metadata\XmpName;
use PDF\Document\Metadata\XmpPacket;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Structure\MarkInformation;
use PDF\Document\Structure\MarkedContentContainer;
use PDF\Document\Structure\MarkedContentIdentifier;
use PDF\Document\Structure\NamespaceRoleMap;
use PDF\Document\Structure\NamespaceRoleMapping;
use PDF\Document\Structure\PageMarkedContent;
use PDF\Document\Structure\StandardStructureNamespace;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureInclusionCorrections;
use PDF\Document\Structure\StructureInclusionRules;
use PDF\Document\Structure\StructureNamespace;
use PDF\Document\Structure\StructureNamespaces;
use PDF\Document\Structure\StructureRole;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\Document\Structure\WtPdfAudit;
use PDF\Document\Structure\WtPdfConformanceLevel;
use PDF\FileStructure;
use PDF\Fonts\TrueType\CharacterMapFactory;
use PDF\Fonts\TrueType\CIDFontProgram;
use PDF\Fonts\TrueType\FontDescriptorFactory;
use PDF\Fonts\TrueType\FontProgram;
use PDF\Fonts\TrueType\TextEncoder;
use PDF\Fonts\TrueType\UnicodeText;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
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

require dirname(__DIR__) . '/vendor/autoload.php';

// PDF 2.0 identifies meaning by the pair (namespace, type), not by a bare name.
// TS 32005 keeps TOC/TOCI/Reference in PDF 1.7 while shared roles use PDF 2.0.
$pdf20 = new StructureNamespace(StandardStructureNamespace::PDF20);
$pdf17 = new StructureNamespace(StandardStructureNamespace::PDF17);
$report = new StructureNamespace('urn:pdftoolkit:example:report', new NamespaceRoleMap(
    new NamespaceRoleMapping(new NameType('ReportParagraph'), new StructureRole(new NameType('P'), $pdf20)),
));

$lines = [
    'PDF 2.0: explicit structure namespaces',
    'A custom report paragraph resolves to the PDF 2.0 P role.',
    'Contents: explicit structure namespaces',
    'The table of contents uses TOC, TOCI and Reference from PDF 1.7.',
    'Mechanical checks support review; they do not certify accessibility.',
];
// Font conversion is an optional module. The core receives explicit normalized
// font program, CID widths, glyph mapping and ToUnicode PDF objects.
$fontBytes = file_get_contents(dirname(__DIR__) . '/vendor/pdftoolkit/pdf-fonts/examples/assets/fonts/NotoSans-Regular.ttf');
if ($fontBytes === false) {
    throw new RuntimeException('Unable to read the demonstration font.');
}
$program = new CIDFontProgram(new FontProgram($fontBytes));
$fontFile = new FontFile2($program->bytes, new FlateDecode());
$map = (new CharacterMapFactory($program))->create($fontFile, new UnicodeText(implode('', $lines)));
$encoder = new TextEncoder($map);
$descriptor = (new FontDescriptorFactory($program))->create($fontFile, new FontDescriptorFlags(4), new NumericType(80));
$font = new Type0Font(new CIDFontType2($descriptor, new CIDToGIDMap($map), new CIDWidths($map)), new ToUnicodeCMap($map));
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
$operators = [];
foreach ($lines as $index => $line) {
    $identifier = new MarkedContentIdentifier(new IntegerType($index));
    $operators[] = $identifier->begin(new NameType($index === 0 ? 'H1' : 'P'));
    $operators[] = new Op\BeginTextObject();
    $operators[] = new Op\SetTextFontAndSize(new NameType('F1'), new IntegerType($index === 0 ? 19 : 11));
    $operators[] = new Op\MoveTextPosition(new IntegerType(40), new IntegerType(770 - 48 * $index));
    $operators[] = new Op\ShowText($encoder->encode(new UnicodeText($line)));
    $operators[] = new Op\EndTextObject();
    $operators[] = new Op\EndMarkedContent();
}
$page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(595), new IntegerType(842)), $resources, new StreamObject(new ContentStreams(...$operators)), new Entry(new NameType('StructParents'), new IntegerType(0)));
$pages = (new PageTreeNode())->addKid($page);
$root = new StructureTreeRoot($pages, namespaces: new StructureNamespaces($pdf20, $pdf17, $report), inclusionRules: new StructureInclusionRules(StructureInclusionCorrections::Resolved336And440));
$document = new StructureElement(new StructureRole(new NameType('Document'), $pdf20));
$container = new MarkedContentContainer($page, new IntegerType(0));
$types = [new StructureRole(new NameType('H1'), $pdf20), new StructureRole(new NameType('ReportParagraph'), $report), new StructureRole(new NameType('Reference'), $pdf17), new StructureRole(new NameType('P'), $pdf20), new StructureRole(new NameType('P'), $pdf20)];
$elements = [];
foreach ($types as $index => $type) {
    $element = new StructureElement($type, $page);
    $element->addKid(new PageMarkedContent($container, new MarkedContentIdentifier(new IntegerType($index))));
    $elements[] = $element;
}
$toc = new StructureElement(new StructureRole(new NameType('TOC'), $pdf17));
$item = new StructureElement(new StructureRole(new NameType('TOCI'), $pdf17));
$elements[2]->addReference($elements[0]);
$item->addKid($elements[2]);
$toc->addKid($item);
$document->addKid($elements[0])->addKid($elements[1])->addKid($toc)->addKid($elements[3])->addKid($elements[4]);
$root->addKid($document);

// These are explicit author assertions for this small, manually reviewed sample.
// The audit below never inserts a declaration or turns a successful check into
// certification. Font program/metric and semantic reviews remain separate evidence.
$level = WtPdfConformanceLevel::Reuse;
$metadata = new MetadataStream(new XmpPacket(
    new XmpLanguageAlternative(new XmpName('dc', new MetadataUri('http://purl.org/dc/elements/1.1/'), 'title'), new XmlText($lines[0]), new XmpLocalizedText('fr', new XmlText('PDF 2.0 : espaces de noms de structure explicites'))),
    new Declarations(new Declaration(new MetadataUri('https://pdfa.org/declarations#iso32005')), new Declaration(new MetadataUri($level->declarationIdentifier()))),
));
$catalog = new DocumentCatalog($pages, ...[...$root->catalogEntries(new MarkInformation(new BooleanType(true))), new MetadataEntry($metadata), new Entry(new NameType('Lang'), new TextString('en-US'))]);
$header = new FileHeader('2.0');
$audit = (new WtPdfAudit())->inspect($catalog, new EffectivePdfVersion($header, $catalog), $level);
$audit->assertNoDetectedFailures();

// stdout stays binary PDF; the optional --audit-json writes a separate review report.
if (in_array('--audit-json', $argv, true)) {
    fwrite(STDERR, json_encode(['detectedFailures' => count($audit->failures()), 'pendingReviews' => array_map(static fn ($finding): array => ['clause' => $finding->clause, 'page' => $finding->pdfPage, 'status' => $finding->status->name, 'message' => $finding->message], $audit->pending())], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
}
$format = in_array('--xref-stream', $argv, true) ? CrossReferenceFormat::Stream : (in_array('--hybrid', $argv, true) ? CrossReferenceFormat::Hybrid : CrossReferenceFormat::Table);
$file = new FileStructure($header, new FileBody($catalog), new IndirectReference($catalog), crossReferenceFormat: $format);
if (isset($argv[1]) && !str_starts_with($argv[1], '--')) {
    $file->writeTo($argv[1]);
} else {
    echo $file->toPdf();
}
