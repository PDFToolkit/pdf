<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Filters\ASCII85Decode;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

$directory = $argv[1] ?? throw new InvalidArgumentException('An output directory is required.');
$name = $argv[2] ?? 'table';
$format = match ($name) {
    'table' => CrossReferenceFormat::Table,
    'stream' => CrossReferenceFormat::Stream,
    'hybrid' => CrossReferenceFormat::Hybrid,
    default => throw new InvalidArgumentException('Unknown cross-reference format.'),
};
$xml = '<?xml version="1.0" encoding="UTF-8"?><x:xmpmeta xmlns:x="adobe:ns:meta/" xmlns:e="urn:example:utf8"><e:label>Révisé — 日本語</e:label></x:xmpmeta>';
$metadata = new StreamObject($xml, new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))), new ASCII85Decode(), new FlateDecode());
$page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)), new ResourceDictionary());
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Metadata'), new IndirectReference($metadata)));
$source = (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
$upgrade = $catalog->with(new Entry(new NameType('Version'), new NameType('2.0')));
$upgraded = (new IncrementalUpdate($source, new FileBody(new IndirectObject($upgrade, $source->binding($catalog)->identifier))))->toRevision();
$retained = (new IncrementalUpdate($upgraded, new FileBody(new IndirectObject($upgrade, $source->binding($catalog)->identifier))))->toRevision();
foreach (['source' => $source, 'upgraded' => $upgraded, 'retained' => $retained] as $kind => $revision) {
    file_put_contents($directory . '/xmp-utf8-' . $name . '-' . $kind . '.pdf', $revision->bytes);
}
