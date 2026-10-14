<?php

declare(strict_types=1);

use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Fonts\TrueType\CIDFontProgram;
use PDF\Fonts\TrueType\CharacterMapFactory;
use PDF\Fonts\TrueType\FontDescriptorFactory;
use PDF\Document\Fonts\CIDToGIDMap;
use PDF\Document\Fonts\CIDWidths;
use PDF\Document\Fonts\FontFile2;
use PDF\Fonts\TrueType\FontProgram;
use PDF\Fonts\TrueType\TextEncoder;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Fonts\TrueType\UnicodeText;
use PDF\Document\Fonts\Type0Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
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
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

require dirname(__DIR__) . '/vendor/autoload.php';

// Tutorial 09: docs/tutorials/09-embedded-font.md.
// The optional PDFToolkit/pdf-fonts package analyzes TrueType bytes.
// The core retains only PDF objects and their explicit values.
// File reading belongs to the application. FontProgram receives bytes
// without knowing paths, URLs or file-access mechanisms.
// Tutorial data are distributed by the module without copies in the core.
$fontBytes = file_get_contents(dirname(__DIR__) . '/vendor/pdftoolkit/pdf-fonts/examples/assets/fonts/NotoSans-Regular.ttf');
if ($fontBytes === false) {
    throw new RuntimeException('Unable to read the bundled demonstration font.');
}
$program = new FontProgram($fontBytes);
$lines = [
    new UnicodeText('Une police incorporée, objet par objet.'),
    new UnicodeText('Français : été, cœur, Noël. Ελληνικά : Αθήνα.'),
    new UnicodeText('Chaque caractère conserve son identité Unicode.'),
];
// The repertoire is known before writing: no mutable global registration.
// Two Unicode characters can have the same GID and different CIDs.
$repertoire = new UnicodeText(implode('', array_map(static fn (UnicodeText $text): string => $text->utf8, $lines)));
// The CID program retains every glyph. The specification requires removing cmap
// for FontFile2/CIDFontType2; mappings remain in our PDF objects.
// The sfnt directory and checksums are recalculated without glyph subsetting.
// Length1 measures embedded-program bytes after filter decoding.
// StemV is explicitly supplied (80 PDF units in this example), because
// TrueType has no universal field giving vertical stem thickness.
// The Symbolic flag suits this repertoire containing Greek (Table 123).
$cidProgram = new CIDFontProgram($program);
$fontFile = new FontFile2($cidProgram->bytes, new FlateDecode());
$map = (new CharacterMapFactory($cidProgram))->create($fontFile, $repertoire);
$encoder = new TextEncoder($map);
$descriptor = (new FontDescriptorFactory($cidProgram))->create($fontFile, new FontDescriptorFlags(4), new NumericType(80));
$glyphMap = new CIDToGIDMap($map, new FlateDecode());
$widths = new CIDWidths($map);
$descendant = new CIDFontType2($descriptor, $glyphMap, $widths);
$toUnicode = new ToUnicodeCMap($map, new FlateDecode());
$font = new Type0Font($descendant, $toUnicode);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));

// Identity-H expects two-byte codes, not the original UTF-8 string.
// The encoder supplies these codes; ToUnicode supplies the reverse mapping
// for selection, search and extraction in PDF readers.
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(22)),
    new MoveTextPosition(new NumericType(48), new NumericType(748)),
    new ShowText($encoder->encode($lines[0])),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(14)),
    new MoveTextPosition(new NumericType(0), new NumericType(-42)),
    new ShowText($encoder->encode($lines[1])),
    new MoveTextPosition(new NumericType(0), new NumericType(-28)),
    new ShowText($encoder->encode($lines[2])),
    new EndTextObject(),
));
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/embedded-font.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
