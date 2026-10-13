<?php

declare(strict_types=1);

use PDF\Document\Fonts\CIDFontType2;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\TrueType\CharacterMap;
use PDF\Document\Fonts\TrueType\CIDFontProgram;
use PDF\Document\Fonts\TrueType\CIDToGIDMap;
use PDF\Document\Fonts\TrueType\CIDWidths;
use PDF\Document\Fonts\TrueType\FontFile2;
use PDF\Document\Fonts\TrueType\FontProgram;
use PDF\Document\Fonts\TrueType\TextEncoder;
use PDF\Document\Fonts\TrueType\ToUnicodeCMap;
use PDF\Document\Fonts\TrueType\UnicodeText;
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

// Tutoriel 09 : docs/tutorials/09-embedded-font.md.
// La lecture du fichier appartient à l'application. FontProgram reçoit des octets,
// sans connaître de chemin, d'URL ou de mécanisme d'accès aux fichiers.
$fontBytes = file_get_contents(__DIR__ . '/assets/fonts/NotoSans-Regular.ttf');
if ($fontBytes === false) {
    throw new RuntimeException('Unable to read the bundled demonstration font.');
}
$program = new FontProgram($fontBytes);
$lines = [
    new UnicodeText('Une police incorporée, objet par objet.'),
    new UnicodeText('Français : été, cœur, Noël. Ελληνικά : Αθήνα.'),
    new UnicodeText('Chaque caractère conserve son identité Unicode.'),
];
// Le répertoire est connu avant l'écriture : aucun enregistrement global mutable.
// Deux caractères Unicode peuvent avoir le même GID et des CID différents.
$repertoire = new UnicodeText(implode('', array_map(static fn (UnicodeText $text): string => $text->utf8, $lines)));
$map = new CharacterMap($program, $repertoire);
$encoder = new TextEncoder($map);

// Le programme CID conserve tous les glyphes. La norme exige de retirer cmap
// pour FontFile2/CIDFontType2 ; les correspondances restent dans nos objets PDF.
// Le répertoire sfnt et ses checksums sont recalculés, sans sous-ensemble de glyphes.
// Length1 mesure les octets du programme incorporé après décodage du filtre.
// StemV est une mesure explicitement fournie (80 unités PDF pour cet exemple),
// car TrueType n'a pas de champ universel donnant l'épaisseur des fûts verticaux.
// Le flag Symbolic convient à ce répertoire comprenant du grec (table 123).
$cidProgram = new CIDFontProgram($program);
$fontFile = new FontFile2($cidProgram, new FlateDecode());
$descriptor = new FontDescriptor($fontFile, new FontDescriptorFlags(4), new NumericType(80));
$glyphMap = new CIDToGIDMap($map, new FlateDecode());
$widths = new CIDWidths($map);
$descendant = new CIDFontType2($descriptor, $glyphMap, $widths);
$toUnicode = new ToUnicodeCMap($map, new FlateDecode());
$font = new Type0Font($descendant, $toUnicode);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));

// Identity-H attend des codes de deux octets, et non la chaîne UTF-8 d'origine.
// L'encodeur fournit ces codes ; ToUnicode fournit la correspondance inverse
// pour la sélection, la recherche et l'extraction dans le lecteur PDF.
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
