<?php

declare(strict_types=1);

use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\CIDToGIDMap;
use PDF\Document\Fonts\CIDWidths;
use PDF\Document\Fonts\CMaps\CMapCode;
use PDF\Document\Fonts\CMaps\CMapCodeRange;
use PDF\Document\Fonts\CMaps\CMapCodeSequence;
use PDF\Document\Fonts\CMaps\CMapName;
use PDF\Document\Fonts\CMaps\CMapSystemInfo;
use PDF\Document\Fonts\CMaps\CodeSpaceRange;
use PDF\Document\Fonts\CMaps\CodeSpaces;
use PDF\Document\Fonts\CMaps\CidChar;
use PDF\Document\Fonts\CMaps\CidMappings;
use PDF\Document\Fonts\CMaps\CidRange;
use PDF\Document\Fonts\CMaps\EncodingCMap;
use PDF\Document\Fonts\CMaps\NotdefRange;
use PDF\Document\Fonts\CMaps\UnicodeArrayRange;
use PDF\Document\Fonts\CMaps\UnicodeChar;
use PDF\Document\Fonts\CMaps\UnicodeCMap;
use PDF\Document\Fonts\CMaps\UnicodeMappings;
use PDF\Document\Fonts\CMaps\UnicodeRange;
use PDF\Document\Fonts\CMaps\UnicodeScalar;
use PDF\Document\Fonts\CMaps\UnicodeScalars;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\FontFile2;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\Type0Font;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Fonts\TrueType\CIDFontProgram;
use PDF\Fonts\TrueType\CharacterMapFactory;
use PDF\Fonts\TrueType\FontDescriptorFactory;
use PDF\Fonts\TrueType\FontProgram;
use PDF\Fonts\TrueType\UnicodeText;
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
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// Le module optionnel analyse le programme TrueType fourni par l'application.
// Le core reçoit ensuite des CID, GID et métriques explicites, sans lire de police.
$bytes = file_get_contents(dirname(__DIR__) . '/vendor/pdftoolkit/pdf-fonts/examples/assets/fonts/NotoSans-Regular.ttf');
if ($bytes === false) {
    throw new RuntimeException('Cannot read the demonstration font supplied by pdf-fonts.');
}
$program = new CIDFontProgram(new FontProgram($bytes));
$fontFile = new FontFile2($program->bytes, new FlateDecode());
$map = (new CharacterMapFactory($program))->create($fontFile, new UnicodeText('ABCDEF éΩﬁ'));
$descriptor = (new FontDescriptorFactory($program))->create($fontFile, new FontDescriptorFlags(4), new NumericType(80));
$descendant = new CIDFontType2($descriptor, new CIDToGIDMap($map, new FlateDecode()), new CIDWidths($map));

// Ce CMap utilise un octet par code, et n'est donc pas Identity-H. Le premier
// dictionnaire décrit l'espace et les six lettres. Le second référence ce CMap
// par /UseCMap et ajoute trois codes distincts A4, A5 et A6.
$code = static fn (string $bytes): CMapCode => new CMapCode(new HexadecimalString($bytes));
$spaces = new CodeSpaces(new CodeSpaceRange($code("\0"), $code("\xff")));
$collection = new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('Identity'), new IntegerType(0));
$base = new EncodingCMap(
    new CMapName(new NameType('PDFToolkit-OneByte-Base')),
    $collection,
    $spaces,
    new CidMappings(
        new CidRange(new CMapCodeRange($code('A'), $code('F')), new IntegerType($map->character(0x41)->cid)),
        new CidChar($code(' '), new IntegerType($map->character(0x20)->cid)),
        new NotdefRange(new CMapCodeRange($code("\0"), $code("\x1f")), new IntegerType(0)),
    ),
);
$encoding = new EncodingCMap(
    new CMapName(new NameType('PDFToolkit-OneByte-Extended')),
    $collection,
    new CodeSpaces(),
    new CidMappings(new CidRange(new CMapCodeRange($code("\xa4"), $code("\xa6")), new IntegerType($map->character(0xe9)->cid))),
    base: $base,
);

// /Encoding sélectionne les glyphes ; /ToUnicode explique leur contenu textuel.
// A..F utilise bfrange incrémental ; A4..A6 utilise des séquences distinctes.
// La ligature ﬁ est un seul glyphe, dont la séquence Unicode extraite est "fi".
$unicode = new ToUnicodeCMap(new UnicodeCMap(
    new CMapName(new NameType('PDFToolkit-OneByte-UCS')),
    $spaces,
    new UnicodeMappings(
        new UnicodeRange(new CMapCodeRange($code('A'), $code('F')), (new UnicodeScalar(new IntegerType(0x41)))->sequence()),
        new UnicodeChar($code(' '), (new UnicodeScalar(new IntegerType(0x20)))->sequence()),
        new UnicodeArrayRange(
            new CMapCodeRange($code("\xa4"), $code("\xa6")),
            (new UnicodeScalar(new IntegerType(0xe9)))->sequence(),
            (new UnicodeScalar(new IntegerType(0x3a9)))->sequence(),
            new UnicodeScalars(new UnicodeScalar(new IntegerType(0x66)), new UnicodeScalar(new IntegerType(0x69))),
        ),
    ),
), new FlateDecode());
$font = new Type0Font($descendant, $unicode, encoding: $encoding);
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))), new Entry(new NameType('F2'), new IndirectReference($font)))));

// Chaque code montré reste un objet. La concaténation est validée avec l'espace
// du CMap ; aucune conversion implicite UTF-8 -> code -> CID n'intervient ici.
$characters = new CMapCodeSequence($encoding->codeSpaces(), $code('A'), $code('B'), $code('C'), $code('D'), $code('E'), $code('F'), $code(' '), $code("\xa4"), $code("\xa5"), $code(' '), $code("\xa6"));
$contents = new ContentStreams(new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), new NumericType(24)), new MoveTextPosition(new NumericType(54), new NumericType(738)), new ShowText(new LiteralString('PDFToolkit - CMaps explicites')), new EndTextObject());
foreach ([['Un encodage incorpore avec des codes sur un octet.', 684], ['Le second CMap herite du premier par UseCMap.', 658], ['CID : selection des glyphes dans la police incorporee.', 612], ['ToUnicode : extraction du sens de chaque code.', 586], ['Resultat ci-dessous : six lettres, accent, grec, ligature.', 540]] as [$text, $y]) {
    $contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), new NumericType(13)), new MoveTextPosition(new NumericType(54), new NumericType($y)), new ShowText(new LiteralString($text)), new EndTextObject());
}
$contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('F2'), new NumericType(34)), new MoveTextPosition(new NumericType(54), new NumericType(466)), new ShowText($characters->string()), new EndTextObject());
foreach ([['A4 = e accentue ; A5 = Omega ; A6 = ligature fi.', 402], ['Les trois destinations viennent d\'un bfrange avec tableau.', 376], ['Le bfrange de A..F incremente seulement le dernier octet.', 350], ['La police et le repertoire restent des objets PDF independants.', 292], ['Source : ISO 32000-1, pages PDF 285-289 et 301-303.', 266]] as [$text, $y]) {
    $contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('F1'), new NumericType(12)), new MoveTextPosition(new NumericType(54), new NumericType($y)), new ShowText(new LiteralString($text)), new EndTextObject());
}
$page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)), $resources, new StreamObject($contents));
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/cmap-font.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
