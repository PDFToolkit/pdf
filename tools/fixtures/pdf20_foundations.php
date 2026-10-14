<?php

declare(strict_types=1);

use PDF\Document\Files as Files;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Properties;
use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29;
use PDF\Specification\Iso32000_2\Dictionary\CatalogDictionaryTable29 as Catalog;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Utf8TextStringEncoding;
use PDF\Syntax\DataStructure\Utf16BETextStringEncoding;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object as O;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$directory = $argv[1];
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create PDF2 fixture directory.');
}
$manifest = [];
foreach (CrossReferenceFormat::cases() as $format) {
    foreach (['header-20', 'catalog-20', 'incremental-20'] as $mode) {
        $utf8 = new Utf8TextStringEncoding();
        $csv = "label,value\nété,2\n";
        $payload = new Files\EmbeddedFileStream($csv, new O\NameType('text/csv'), modificationDate: new Date(new DateTimeImmutable('2026-10-14T00:00:00Z')), filters: new FlateDecode());
        $specification = new Files\FileSpecification(new LiteralString('data.csv'), new Files\FileSpecificationOptions(embedded: new Files\EmbeddedFileBindings(new Files\EmbeddedFileBinding(Files\EmbeddedFileKey::File, $payload))), new Files\AssociatedFileRelationship(Files\AFRelationship::Data));
        $files = new Files\AssociatedFiles(new O\IndirectReference($specification));
        $resources = new ResourceDictionary(new Properties(new O\DictionaryType(new Entry(new O\NameType('AFData'), new Files\AssociatedFilesPropertyList($files)))));
        $marked = new O\StreamObject(new ContentStreams(new Files\BeginAssociatedFilesMarkedContent(new O\NameType('AFData')), new Op\EndMarkedContent()));
        $rectangle = new Rectangle(new O\NumericType(0), new O\NumericType(0), new O\NumericType(200), new O\NumericType(100));
        $incremental = $mode === 'incremental-20';
        $initialContent = $incremental ? new O\StreamObject("% Initial PDF1.7 content\n") : $marked;
        $page = new PageObject($rectangle, $incremental ? new ResourceDictionary() : $resources, $initialContent, ...($incremental ? [] : [new Entry(new O\NameType('AF'), $files)]));
        $pages = (new PageTreeNode())->addKid($page);
        $version = $mode === 'header-20' ? [] : [new Catalog\VersionEntry(new O\NameType('2.0'))];
        $catalog = $incremental ? new DocumentCatalog($pages) : new CatalogDictionaryTable29(new Catalog\TypeEntry(new O\NameType('Catalog')), new Catalog\PagesEntry(new O\IndirectReference($pages)), new Catalog\AFEntry($files), ...$version);
        $text = 'UTF8: été, Ω, 😀';
        $utf16 = 'UTF16: été, Ω, 😀';
        $info = new O\DictionaryType(new Entry(new O\NameType('Title'), new TextString($text, $utf8)), new Entry(new O\NameType('Subject'), new TextString($utf16, new Utf16BETextStringEncoding())), new Entry(new O\NameType('Default'), new TextString('Default PDFDoc text')), new Entry(new O\NameType('Opaque'), new LiteralString("\xef\xbb\xbf\0\xff")));
        $initialInfo = $incremental ? new O\DictionaryType(new Entry(new O\NameType('Title'), new TextString('Initial PDF1.7 title'))) : $info;
        $file = new FileStructure(new FileHeader($mode === 'header-20' ? '2.0' : '1.7'), new FileBody($catalog), trailerEntries: new O\DictionaryType(new Entry(new O\NameType('Info'), new O\IndirectReference($initialInfo))), crossReferenceFormat: $format);
        $snapshot = (new FileWriter())->revision($file);
        $prefix = null;
        if ($incremental) {
            $prefix = 'base-' . $format->name . '.pdf';
            $snapshot->writeTo($directory . '/' . $prefix);
            $replacement = new CatalogDictionaryTable29(new Catalog\TypeEntry(new O\NameType('Catalog')), new Catalog\PagesEntry(new O\IndirectReference($pages)), new Catalog\AFEntry($files), ...$version);
            $frozenPage = $snapshot->binding($page)->dictionary ?? throw new LogicException('Missing preceding page dictionary.');
            $updatedPage = $frozenPage->with(new Entry(new O\NameType('Resources'), $resources))->with(new Entry(new O\NameType('AF'), $files));
            $snapshot = (new IncrementalUpdate($snapshot, new FileBody(new O\IndirectObject($replacement, $snapshot->binding($catalog)->identifier), new O\IndirectObject($updatedPage, $snapshot->binding($page)->identifier), new O\IndirectObject($marked, $snapshot->binding($initialContent)->identifier)), trailerEntries: new O\DictionaryType(new Entry(new O\NameType('Info'), new O\IndirectReference($info)))))->toRevision();
        }
        $filename = strtolower($format->name) . '-' . $mode . '.pdf';
        $snapshot->writeTo($directory . '/' . $filename);
        $manifest[] = ['file' => $filename, 'baseFile' => $prefix, 'format' => $format->name, 'header' => $mode === 'header-20' ? '%PDF-2.0' : '%PDF-1.7', 'title' => $text, 'subject' => $utf16, 'csv' => $csv];
    }
}
file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo count($manifest) . " PDF2 fixtures\n";
