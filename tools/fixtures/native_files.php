<?php

declare(strict_types=1);

require __DIR__ . '/linearization.php';

use PDF\Document\Collections as C;
use PDF\Document\Files as F;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeChildren;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\NameDictionary;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\LZWDecode;
use PDF\Syntax\Filters\EarlyChange;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

/** @return array{FileStructure, F\FileSpecification, C\CollectionItem, array<string, string>} */
function nativeFilesFixture(CrossReferenceFormat $format, bool $packed): array
{
    $payloads = ['Main' => "original\0data\xff\n" . str_repeat('sample,19.75\n', 100), 'Related' => '{"total":19.75}', 'Notes' => "Notes\r\n", 'ResourceFork' => "\0Mac fork\xff"];
    $date = new Date(new DateTimeImmutable('2026-10-13T09:00:00+02:00'));
    $mac = new F\MacFileInformation(new F\MacFileCode(new LiteralString('CARO')), new F\MacFileCode(new LiteralString('TEXT')), new StreamObject($payloads['ResourceFork'], filters: new FlateDecode()));
    $main = new F\EmbeddedFileStream($payloads['Main'], new NameType('application/octet-stream'), $date, $date, $mac, new FlateDecode());
    $related = new F\EmbeddedFileStream($payloads['Related'], new NameType('application/json'), filters: new LZWDecode(new EarlyChange(new IntegerType(0))));
    $notes = new F\EmbeddedFileStream($payloads['Notes'], new NameType('text/plain'));
    $schema = new C\CollectionSchema(
        new C\CollectionSchemaField(new NameType('Amount'), new C\CollectionField(C\CollectionFieldType::Number, new TextString('Montant'))),
        new C\CollectionSchemaField(new NameType('Title'), new C\CollectionField(C\CollectionFieldType::Text, new TextString('Titre'))),
        new C\CollectionSchemaField(new NameType('Created'), new C\CollectionField(C\CollectionFieldType::Date, new TextString('Date'))),
    );
    $item = new C\CollectionItem($schema, new C\CollectionItemValue(new NameType('Amount'), new C\CollectionSubitem(new NumericType(19.75), new TextString('EUR '))), new C\CollectionItemValue(new NameType('Title'), new TextString('Main')), new C\CollectionItemValue(new NameType('Created'), $date));
    $noteItem = new C\CollectionItem($schema, new C\CollectionItemValue(new NameType('Amount'), new C\CollectionSubitem(prefix: new TextString('No data'))), new C\CollectionItemValue(new NameType('Title'), new TextString('Notes')));
    $ef = new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $main), new F\EmbeddedFileBinding(F\EmbeddedFileKey::UnicodeFile, $main));
    $rf = new F\RelatedFileBindings($ef, new F\RelatedFileBinding(F\EmbeddedFileKey::File, new F\RelatedFiles(new F\RelatedFile(new LiteralString('total.json'), $related))));
    $file = new F\FileSpecification(new LiteralString('data.bin'), new F\FileSpecificationOptions($ef, $rf, new TextString('Main binary payload'), new F\FileSpecificationIdentifiers(new LiteralString('opaque permanent'), new LiteralString('opaque changing')), new BooleanType(false), collectionItem: $item, additionalNames: new F\FileSpecificationName(F\EmbeddedFileKey::UnicodeFile, new TextString('données.bin'))));
    $noteFile = new F\FileSpecification(new LiteralString('notes.txt'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $notes)), collectionItem: $noteItem));
    $tree = new NameTree(new NameTreeChildren(new NameTree(new NameTreeEntry(new LiteralString('Main'), new IndirectReference($file))), new NameTree(new NameTreeEntry(new LiteralString('Notes'), new IndirectReference($noteFile)))));
    $sort = new C\CollectionSort($schema, new C\CollectionSortFields(new NameType('Amount'), new NameType('Title')), new C\CollectionSortDirections(new BooleanType(false)));
    $collection = new C\Collection($schema, initialDocument: new LiteralString('Main'), sort: $sort);
    $font = new Type1Font(StandardFont::Helvetica);
    $resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
    $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(300), new IntegerType(400)), $resources, new StreamObject('BT /F1 12 Tf 20 350 Td (Native embedded files) Tj ET'));
    $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $collection->catalogEntry(), new Entry(new NameType('Names'), new NameDictionary(new Entry(new NameType('EmbeddedFiles'), new IndirectReference($tree)))), new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3)))))));
    $body = new FileBody($catalog);
    if ($packed) {
        $body->addObject(new ObjectStream(new ObjectStreamEntry(new ObjectNumber(50), $item), new ObjectStreamEntry(new ObjectNumber(51), $noteItem), new FlateDecode()));
    }
    $id = new HexadecimalString(str_repeat('i', 16));
    return [new FileStructure(new FileHeader(), $body, new IndirectReference($catalog), new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id, $id))), $format), $file, $item, $payloads];
}

$manifest = [];
foreach (linearizationSecurityCases() as [$securityName, $encryption]) {
    foreach (CrossReferenceFormat::cases() as $format) {
        foreach ($format === CrossReferenceFormat::Table ? [false] : [false, true] as $packed) {
            foreach ([false, true] as $linearized) {
                [$file, $mainFile, $item, $payloads] = nativeFilesFixture($format, $packed);
                $writer = $linearized ? new LinearizedWriter(encryption: $encryption) : new FileWriter(encryption: $encryption);
                $base = $writer->revision($file);
                $frozen = $base->binding($item)->dictionary ?? throw new LogicException('The fixture requires a frozen collection item.');
                $changed = $frozen->with(new Entry(new NameType('Amount'), new C\CollectionSubitem(new NumericType(20.5), new TextString('EUR '))));
                $next = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $base->binding($item)->identifier))));
                $files = [];
                foreach ([$base, $next] as $index => $revision) {
                    $name = $securityName . '-' . $format->name . '-' . (int) $packed . '-' . (int) $linearized . '-' . $index . '.pdf';
                    file_put_contents($directory . '/' . $name, $revision->bytes);
                    $files[] = ['file' => $name, 'amount' => $index === 0 ? 19.75 : 20.5];
                }
                $manifest[] = ['files' => $files, 'password' => $encryption === null ? '' : 'user', 'linearized' => $linearized, 'payloads' => array_map(base64_encode(...), $payloads)];
            }
        }
    }
}
file_put_contents($directory . '/manifest-native-files.json', json_encode($manifest, JSON_THROW_ON_ERROR));
echo count($manifest), ' attachment/collection histories / ', count($manifest) * 2, ' PDF files', PHP_EOL;
