<?php

declare(strict_types=1);

use PDF\Document\Files\AFRelationship;
use PDF\Document\Files\AssociatedFileRelationship;
use PDF\Document\Files\AssociatedFiles;
use PDF\Document\Files\AssociatedFilesPropertyList;
use PDF\Document\Files\BeginAssociatedFilesMarkedContent;
use PDF\Document\Files\EmbeddedFileBinding;
use PDF\Document\Files\EmbeddedFileBindings;
use PDF\Document\Files\EmbeddedFileKey;
use PDF\Document\Files\EmbeddedFileStream;
use PDF\Document\Files\FileSpecification;
use PDF\Document\Files\FileSpecificationOptions;
use PDF\Document\Files\FileSpecificationName;
use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\CIDToGIDMap;
use PDF\Document\Fonts\CIDWidths;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\FontFile2;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\Type0Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Resources\Properties;
use PDF\FileStructure;
use PDF\Fonts\TrueType\CharacterMapFactory;
use PDF\Fonts\TrueType\CIDFontProgram;
use PDF\Fonts\TrueType\FontDescriptorFactory;
use PDF\Fonts\TrueType\FontProgram;
use PDF\Fonts\TrueType\TextEncoder;
use PDF\Fonts\TrueType\UnicodeText;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29\AFEntry;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29\PagesEntry;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29\TypeEntry;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Utf8TextStringEncoding;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// Text strings in metadata and filenames use PDF2's UTF8 BOM. This is not a
// font encoding: text-showing operators below still receive explicit CID codes.
$utf8 = new Utf8TextStringEncoding();
$title = new TextString('PDF 2.0 : fichiers associés', $utf8);
$csv = "produit,quantite,prix_unitaire\nPapier,2,3.50\nEnveloppes,4,1.20\n";
$payload = new EmbeddedFileStream(
    $csv,
    new NameType('text/csv'),
    modificationDate: new Date(new DateTimeImmutable('2026-10-14T00:00:00+00:00')),
    filters: new FlateDecode(),
);
$specification = new FileSpecification(
    new LiteralString('donnees.csv'),
    new FileSpecificationOptions(
        embedded: new EmbeddedFileBindings(new EmbeddedFileBinding(EmbeddedFileKey::File, $payload), new EmbeddedFileBinding(EmbeddedFileKey::UnicodeFile, $payload)),
        description: new TextString('Données source de cette page.', $utf8),
        additionalNames: new FileSpecificationName(EmbeddedFileKey::UnicodeFile, new TextString('données.csv', $utf8)),
    ),
    new AssociatedFileRelationship(AFRelationship::Data),
);
// The same indirect identity is associated with both the document and its page.
// /AF expresses a relationship; it is distinct from the EmbeddedFiles name tree.
$files = new AssociatedFiles(new IndirectReference($specification));

$lines = [
    new UnicodeText('PDF 2.0 : fichiers associés'),
    new UnicodeText('Un fichier CSV accompagne ce document et cette page.'),
    new UnicodeText('Sa relation est Data ; ses octets et sa date sont explicites.'),
    new UnicodeText('Les chaînes de métadonnées utilisent un UTF-8 avec marqueur.'),
    new UnicodeText('Le texte visible utilise une police incorporée et une CMap.'),
];
// Font conversion remains optional and outside the core. Its distributed fixture
// supplies a complete font program; each PDF font/CID/Unicode object is explicit.
$fontBytes = file_get_contents(dirname(__DIR__) . '/vendor/pdftoolkit/pdf-fonts/examples/assets/fonts/NotoSans-Regular.ttf');
if ($fontBytes === false) {
    throw new RuntimeException('Unable to read the bundled demonstration font.');
}
$program = new CIDFontProgram(new FontProgram($fontBytes));
$fontFile = new FontFile2($program->bytes, new FlateDecode());
$repertoire = new UnicodeText(implode('', array_map(static fn (UnicodeText $line): string => $line->utf8, $lines)));
$map = (new CharacterMapFactory($program))->create($fontFile, $repertoire);
$encoder = new TextEncoder($map);
$descriptor = (new FontDescriptorFactory($program))->create($fontFile, new FontDescriptorFlags(4), new NumericType(80));
$descendant = new CIDFontType2($descriptor, new CIDToGIDMap($map, new FlateDecode()), new CIDWidths($map));
$font = new Type0Font($descendant, new ToUnicodeCMap($map, new FlateDecode()));
// MCAF associates a content sequence through a named property dictionary.
$resources = new ResourceDictionary(
    new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))),
    new Properties(new DictionaryType(new Entry(new NameType('AFData'), new AssociatedFilesPropertyList($files)))),
);
$operators = [new BeginAssociatedFilesMarkedContent(new NameType('AFData')), new Op\BeginTextObject(), new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(20)), new Op\MoveTextPosition(new NumericType(48), new NumericType(748))];
foreach ($lines as $index => $line) {
    if ($index !== 0) {
        $operators[] = new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(12));
        $operators[] = new Op\MoveTextPosition(new NumericType(0), new NumericType($index === 1 ? -42 : -28));
    }
    $operators[] = new Op\ShowText($encoder->encode($line));
}
$operators[] = new Op\EndTextObject();
$operators[] = new Op\EndMarkedContent();
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)),
    $resources,
    new StreamObject(new ContentStreams(...$operators)),
    new Entry(new NameType('AF'), $files),
);
$pages = (new PageTreeNode())->addKid($page);
// This definition belongs to ISO32000-2 Table29, separately from ISO32000-1 Table28.
$catalog = new CatalogDictionaryTable29(new TypeEntry(new NameType('Catalog')), new PagesEntry(new IndirectReference($pages)), new AFEntry($files));
// Info is retained for a simple UTF8 string demonstration. It is deprecated in
// PDF2.0; this example does not claim complete PDF2.0 or archival-profile conformance.
$info = new DictionaryType(new Entry(new NameType('Title'), $title));
$file = new FileStructure(new FileHeader(PdfVersion::PDF20), new FileBody($catalog), trailerEntries: new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($info))));
$path = $argv[1] ?? __DIR__ . '/pdf20-associated-files.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
