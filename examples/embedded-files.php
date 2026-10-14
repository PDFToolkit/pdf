<?php

declare(strict_types=1);

use PDF\Document\Collections as C;
use PDF\Document\Files as F;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\NameDictionary;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
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
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// Bytes are supplied explicitly. The core opens no external files.
// Params Size and CheckSum are derived before Flate and encryption.
$created = new Date(new DateTimeImmutable('2026-10-13T09:00:00+02:00'));
$csv = new F\EmbeddedFileStream("article,montant\nA,12.50\nB,7.25\n", new NameType('text/csv'), $created, $created, filters: new FlateDecode());
$json = new F\EmbeddedFileStream('{"total":19.75}', new NameType('application/json'), filters: new FlateDecode());
$notes = new F\EmbeddedFileStream("Les montants sont exprimes en euros.\n", new NameType('text/plain'), filters: new FlateDecode());

// A collection names and types its fields. A prefix is presentation data;
// it is never concatenated to the number used for sorting.
$schema = new C\CollectionSchema(
    new C\CollectionSchemaField(new NameType('Title'), new C\CollectionField(C\CollectionFieldType::Text, new TextString('Document'), new IntegerType(0))),
    new C\CollectionSchemaField(new NameType('Amount'), new C\CollectionField(C\CollectionFieldType::Number, new TextString('Montant'), new IntegerType(1))),
    new C\CollectionSchemaField(new NameType('Created'), new C\CollectionField(C\CollectionFieldType::Date, new TextString('Date'), new IntegerType(2))),
);
$measureItem = new C\CollectionItem(
    $schema,
    new C\CollectionItemValue(new NameType('Title'), new TextString('Mesures')),
    new C\CollectionItemValue(new NameType('Amount'), new C\CollectionSubitem(new NumericType(19.75), new TextString('EUR '))),
    new C\CollectionItemValue(new NameType('Created'), $created),
);
$noteItem = new C\CollectionItem(
    $schema,
    new C\CollectionItemValue(new NameType('Title'), new TextString('Notes')),
    new C\CollectionItemValue(new NameType('Amount'), new C\CollectionSubitem(prefix: new TextString('Aucun montant'))),
);

// EF.F and EF.UF target the same stream with their corresponding names.
// RF.F adds a JSON export related to the CSV without declaring it the initial document.
$ef = new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $csv), new F\EmbeddedFileBinding(F\EmbeddedFileKey::UnicodeFile, $csv));
$related = new F\RelatedFileBindings($ef, new F\RelatedFileBinding(
    F\EmbeddedFileKey::File,
    new F\RelatedFiles(new F\RelatedFile(new LiteralString('total.json'), $json)),
));
$measureFile = new F\FileSpecification(new LiteralString('mesures.csv'), new F\FileSpecificationOptions(
    embedded: $ef,
    related: $related,
    description: new TextString('Mesures et export associe'),
    collectionItem: $measureItem,
    additionalNames: new F\FileSpecificationName(F\EmbeddedFileKey::UnicodeFile, new TextString('mesures.csv')),
));
$noteFile = new F\FileSpecification(new LiteralString('notes.txt'), new F\FileSpecificationOptions(
    embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $notes)),
    description: new TextString('Notes du dossier'),
    collectionItem: $noteItem,
));
$attachments = new F\EmbeddedFiles(new F\EmbeddedFileAttachment(new LiteralString('Mesures'), $measureFile), new F\EmbeddedFileAttachment(new LiteralString('Notes'), $noteFile));

// S contains two fields; A supplies only one direction. Amount therefore
// descends and Title takes default true. D is a Names.EmbeddedFiles key.
$sort = new C\CollectionSort($schema, new C\CollectionSortFields(new NameType('Amount'), new NameType('Title')), new C\CollectionSortDirections(new BooleanType(false)));
$collection = new C\Collection($schema, C\CollectionView::Details, new LiteralString('Mesures'), $sort);

// This page is the container. Readers manage attachment and collection
// interfaces; the text below makes the portfolio inspectable everywhere.
$n = static fn (int|float $value): NumericType => new NumericType($value);
$resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))))));
$operators = [];
$lines = [
    [28, 'Fichiers incorpores et collections'],
    [13, 'Un contenant PDF, deux pieces jointes, un export JSON associe.'],
    [18, 'Mesures / mesures.csv'],
    [12, 'CSV : articles A et B, total 19.75.'],
    [12, 'CI.Amount = 19.75 ; le prefixe EUR ne change pas la valeur.'],
    [12, 'RF.F : total.json contient le meme total.'],
    [18, 'Notes / notes.txt'],
    [12, 'Le sous-element affiche un prefixe, sans inventer de montant.'],
    [18, 'Relations natives'],
    [12, 'Names.EmbeddedFiles trie les cles et reference les Filespec.'],
    [12, 'EF reference les flux ; CI reference les metadonnees typees.'],
    [12, 'Collection.D = Mesures ; Schema et Sort decrivent la presentation.'],
    [18, 'Octets et controles'],
    [12, 'Params.Size compte les octets originaux, avant compression.'],
    [12, 'Params.CheckSum est leur MD5 de format, sans preuve de confiance.'],
    [12, 'Flate compresse ensuite chaque flux incorpore.'],
    [12, 'Aucun chemin, lien distant ou programme joint n est execute.'],
];
$y = 736;
foreach ($lines as [$size, $line]) {
    array_push($operators, new Op\SetNonstrokingRGBColor($n(0.1), $n(0.2), $n(0.28)), new Op\BeginTextObject(), new Op\SetTextFontAndSize(new NameType('F1'), $n($size)), new Op\MoveTextPosition($n(42), $n($y)), new Op\ShowText(new LiteralString($line)), new Op\EndTextObject());
    $y -= $size >= 18 ? 45 : 31;
}
$page = new PageObject(new Rectangle($n(0), $n(0), $n(612), $n(792)), $resources, new StreamObject(new ContentStreams(...$operators)));
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $collection->catalogEntry(), new Entry(new NameType('Names'), new NameDictionary($attachments->nameDictionaryEntry())));
$file = new FileStructure(new FileHeader(), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/output/embedded-files.pdf';
$directory = dirname($path);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create the example output directory.');
}
$file->writeTo($path);
echo $path, PHP_EOL;
