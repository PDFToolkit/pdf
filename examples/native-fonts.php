<?php

declare(strict_types=1);

use PDF\Document\Fonts\CIDDefaultVerticalMetrics;
use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\CIDGlyphIndex;
use PDF\Document\Fonts\CIDGlyphIndexMap;
use PDF\Document\Fonts\CIDMetrics;
use PDF\Document\Fonts\CIDVerticalMetric;
use PDF\Document\Fonts\CIDVerticalMetrics;
use PDF\Document\Fonts\CIDVerticalRun;
use PDF\Document\Fonts\CIDVerticalWidths;
use PDF\Document\Fonts\CIDWidthArray;
use PDF\Document\Fonts\CIDWidthRun;
use PDF\Document\Fonts\CMaps\CMapCode;
use PDF\Document\Fonts\CMaps\CMapName;
use PDF\Document\Fonts\CMaps\CMapWritingMode;
use PDF\Document\Fonts\CMaps\CodeSpaceRange;
use PDF\Document\Fonts\CMaps\CodeSpaces;
use PDF\Document\Fonts\CMaps\IdentityEncoding;
use PDF\Document\Fonts\CMaps\UnicodeChar;
use PDF\Document\Fonts\CMaps\UnicodeCMap;
use PDF\Document\Fonts\CMaps\UnicodeMappings;
use PDF\Document\Fonts\CMaps\UnicodeScalar;
use PDF\Document\Fonts\Encodings\EncodingDictionary;
use PDF\Document\Fonts\Encodings\EncodingDifference;
use PDF\Document\Fonts\Encodings\EncodingDifferences;
use PDF\Document\Fonts\Encodings\NamedFontEncoding;
use PDF\Document\Fonts\Encodings\PredefinedEncoding;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\FontFile2;
use PDF\Document\Fonts\FontFile3;
use PDF\Document\Fonts\FontFile3Subtype;
use PDF\Document\Fonts\FontProgramMetadata;
use PDF\Document\Fonts\OpenTypeOutline;
use PDF\Document\Fonts\SimpleFontMetrics;
use PDF\Document\Fonts\SimpleWidths;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\TrueTypeFont;
use PDF\Document\Fonts\Type0Font;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Fonts\Type3CharProc;
use PDF\Document\Fonts\Type3CharProcs;
use PDF\Document\Fonts\Type3Font;
use PDF\Document\Fonts\Type3Glyph;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Fonts\TrueType\CIDFontProgram;
use PDF\Fonts\TrueType\FontDescriptorFactory;
use PDF\Fonts\TrueType\FontProgram;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidthAndBoundingBox;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
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

$number = static fn (int|float $value): NumericType => new NumericType($value);
$code = static fn (string $bytes): CMapCode => new CMapCode(new HexadecimalString($bytes));

// A Type3 font contains its own PDF drawing instructions. /CharProcs maps
// each glyph name to an indirect stream; /Differences selects these
// names from bytes 41 and 42. Both d1 operators declare width 600.
// The 0.001 matrix converts glyph coordinates into text space.
$square = new Type3Glyph(new Type3GlyphContentStream(
    new SetGlyphWidthAndBoundingBox($number(600), $number(0), $number(0), $number(0), $number(500), $number(500)),
    new AppendRectangle($number(0), $number(0), $number(500), $number(500)),
    new FillPathNonzero(),
));
$cross = new Type3Glyph(new Type3GlyphContentStream(
    new SetGlyphWidthAndBoundingBox($number(600), $number(0), $number(0), $number(0), $number(500), $number(500)),
    new AppendRectangle($number(200), $number(0), $number(100), $number(500)),
    new FillPathNonzero(),
    new AppendRectangle($number(0), $number(200), $number(500), $number(100)),
    new FillPathNonzero(),
));
$byteSpaces = new CodeSpaces(new CodeSpaceRange($code("\0"), $code("\xff")));
$shapeUnicode = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Shapes-UCS')), $byteSpaces, new UnicodeMappings(
    new UnicodeChar($code('A'), (new UnicodeScalar(new IntegerType(0x25A0)))->sequence()),
    new UnicodeChar($code('B'), (new UnicodeScalar(new IntegerType(0x2B)))->sequence()),
)));
$shapes = new Type3Font(
    new Rectangle($number(0), $number(0), $number(500), $number(500)),
    new Matrix(a: $number(0.001), d: $number(0.001)),
    new Type3CharProcs(new Type3CharProc(new NameType('square'), $square), new Type3CharProc(new NameType('cross'), $cross)),
    new EncodingDictionary(new EncodingDifferences(new EncodingDifference(new IntegerType(65), new NameType('square'), new NameType('cross')))),
    new SimpleWidths(new IntegerType(65), $number(600), $number(600)),
    new DictionaryType(),
    toUnicode: $shapeUnicode,
);

// The optional font module reads the external resource. The core receives
// normalized programs and their PDF values: font constructors perform
// no parsing. The simple program retains cmap tables; the normalized
// CID program removes cmap following ISO 9.9.
$bytes = file_get_contents(dirname(__DIR__) . '/vendor/pdftoolkit/pdf-fonts/examples/assets/fonts/NotoSans-Regular.ttf');
if ($bytes === false) {
    throw new RuntimeException('Cannot read the bundled demonstration font.');
}
$source = new FontProgram($bytes);
$cidProgram = new CIDFontProgram($source);
$cidFile = new FontFile2($cidProgram->bytes, new FlateDecode());
$cidDescriptor = (new FontDescriptorFactory($cidProgram))->create($cidFile, new FontDescriptorFlags(4), $number(80));
$metadata = new FontProgramMetadata(new StreamObject('<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about=""/></rdf:RDF></x:xmpmeta>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML')))));
$simpleFile = new FontFile3($bytes, FontFile3Subtype::OpenType, OpenTypeOutline::Glyf, new FlateDecode(), $metadata);
$box = array_map(static fn (int $value): NumericType => new NumericType($source->metrics->scale($value)), $source->boundingBox);
$simpleDescriptor = new FontDescriptor($simpleFile, new NameType($source->postScriptName), new FontDescriptorFlags(32), new Rectangle(...$box), $number($source->italicAngle), $number($source->metrics->scale($source->ascent)), $number($source->metrics->scale($source->descent)), $number($source->metrics->scale($source->capHeight ?? 700)), $number(80));
$simpleWidths = [];
$cidIndices = [new CIDGlyphIndex(new IntegerType(0), new IntegerType(0))];
$cidWidths = [];
$cidUnicodeMappings = [];
foreach (range(65, 70) as $scalar) {
    $gid = $source->cmap->glyphId($scalar);
    $width = $number($source->metrics->pdfWidth($gid));
    $simpleWidths[] = $width;
    $cid = $scalar - 64;
    $cidIndices[] = new CIDGlyphIndex(new IntegerType($cid), new IntegerType($gid));
    $cidWidths[] = $width;
    $cidUnicodeMappings[] = new UnicodeChar($code(pack('n', $cid)), (new UnicodeScalar(new IntegerType($scalar)))->sequence());
}
$simple = new TrueTypeFont(new NameType($source->postScriptName), new SimpleFontMetrics(new SimpleWidths(new IntegerType(65), ...$simpleWidths), $simpleDescriptor), new NamedFontEncoding(PredefinedEncoding::WinAnsi));

// Vertical mode comes from Identity-V, not the TrueType program. DW2 supplies
// [originY, displacementY] and W2 supplies [displacementY, originX, originY] per CID.
// CIDs 1 and 2 have explicit metrics; others use DW2 and half their
// horizontal width to calculate the default X origin.
$vertical = new CIDVerticalMetrics(new CIDDefaultVerticalMetrics($number(880), $number(-1000)), new CIDVerticalWidths(new CIDVerticalRun(
    new IntegerType(1),
    new CIDVerticalMetric($number(-1200), $number(300), $number(800)),
    new CIDVerticalMetric($number(-1000), $number(300), $number(800)),
)));
$descendant = new CIDFontType2($cidDescriptor, new CIDGlyphIndexMap(...$cidIndices), new CIDMetrics(widths: new CIDWidthArray(new CIDWidthRun(new IntegerType(1), ...$cidWidths)), vertical: $vertical));
$cidUnicode = new ToUnicodeCMap(new UnicodeCMap(new CMapName(new NameType('Vertical-UCS')), new CodeSpaces(new CodeSpaceRange($code("\0\0"), $code("\xff\xff"))), new UnicodeMappings(...$cidUnicodeMappings)));
$verticalFont = new Type0Font($descendant, $cidUnicode, encoding: new IdentityEncoding(CMapWritingMode::Vertical));
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))),
    new Entry(new NameType('F2'), new IndirectReference($shapes)),
    new Entry(new NameType('F3'), new IndirectReference($simple)),
    new Entry(new NameType('F4'), new IndirectReference($verticalFont)),
)));
$contents = new ContentStreams();
foreach ([['PDFToolkit - Fontes natives', 740, 24], ['Type3 : chaque glyphe est un stream PDF explicite.', 685, 13], ['Les octets A et B dessinent un carre et une croix.', 659, 13], ['ToUnicode extrait les caracteres U+25A0 et U+002B.', 580, 12], ['TrueType simple : programme OpenType/glyf incorpore.', 526, 13], ['Largeurs par code et encodage WinAnsi explicites.', 500, 13], ['CID vertical : Identity-V, CIDToGIDMap, DW2 et W2.', 400, 13], ['Les trois lettres ci-dessous sont avancees vers le bas.', 374, 12], ['Source : ISO 32000-1, pages PDF 262-280 et 289-300.', 200, 12]] as [$text, $y, $size]) {
    $contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), $number($size)), new MoveTextPosition($number(54), $number($y)), new ShowText(new LiteralString($text)), new EndTextObject());
}
$contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('F2'), $number(54)), new MoveTextPosition($number(54), $number(610)), new ShowText(new HexadecimalString('AB')), new EndTextObject());
$contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('F3'), $number(32)), new MoveTextPosition($number(54), $number(450)), new ShowText(new HexadecimalString('ABCDEF')), new EndTextObject());
$contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('F4'), $number(26)), new MoveTextPosition($number(90), $number(342)), new ShowText(new HexadecimalString("\0\x01\0\x02\0\x03")), new EndTextObject());
$page = new PageObject(new Rectangle($number(0), $number(0), $number(612), $number(792)), $resources, new StreamObject($contents));
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/native-fonts.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
