<?php

declare(strict_types=1);

use PDF\Document\Artifacts\BatesArtifact;
use PDF\Document\Extensions\DeveloperExtension;
use PDF\Document\Extensions\DeveloperExtensionEntry;
use PDF\Document\Extensions\DeveloperExtensions;
use PDF\Document\Extensions\DeveloperPrefix;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
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
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// Tutoriel 13 : docs/tutorials/13-adobe-extensions.md.
// Le catalogue annonce le niveau Adobe utilisé ; le flux de page matérialise
// ensuite l'une de ses extensions, la numérotation Bates (supplément, p.100).
$extensions = new DeveloperExtensions(new DeveloperExtensionEntry(
    new DeveloperPrefix(new NameType('ADBE')),
    new DeveloperExtension(new NameType('1.7'), new IntegerType(3)),
));

$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));

// Les propriétés Bates décrivent des numéros déjà choisis par l'application.
// Elles ne dessinent rien. BDC/EMC classent le texte dessiné comme un artefact
// de pagination, distinct du contenu documentaire.
$numbers = new ArrayType(new LiteralString('DOC-000001'), new LiteralString('EXH-000019'));
$bates = new BatesArtifact($numbers);
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(24)),
    new MoveTextPosition(new NumericType(64), new NumericType(716)),
    new ShowText(new LiteralString('PDFToolkit - extensions Adobe')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(13)),
    new MoveTextPosition(new NumericType(0), new NumericType(-46)),
    new ShowText(new LiteralString('PDF 1.7, Adobe ExtensionLevel 3')),
    new MoveTextPosition(new NumericType(0), new NumericType(-30)),
    new ShowText(new LiteralString('Le catalogue contient /Extensions /ADBE.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-26)),
    new ShowText(new LiteralString('Chaque numero Bates reste un objet chaine explicite.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-26)),
    new ShowText(new LiteralString('Leur liste est fournie par /Contents dans un artefact.')),
    new EndTextObject(),
    // Le même emplacement peut porter plusieurs index documentaires.
    new BeginMarkedContentWithProperties(new NameType('Artifact'), $bates),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(64), new NumericType(48)),
    new ShowText(new LiteralString('DOC-000001  |  EXH-000019')),
    new EndTextObject(),
    new EndMarkedContent(),
));
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages, new Extensions($extensions));
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/adobe-extensions.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
