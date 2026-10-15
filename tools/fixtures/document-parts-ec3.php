<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\Parts\DocumentPart;
use PDF\Document\Parts\DocumentPartEntry;
use PDF\Document\Parts\DocumentPartMetadata;
use PDF\Document\Parts\DocumentPartRoot;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

$output = $argv[1] ?? throw new InvalidArgumentException('An output file is required.');
$format = match ($argv[2] ?? 'table') {
    'table' => CrossReferenceFormat::Table,
    'stream' => CrossReferenceFormat::Stream,
    'hybrid' => CrossReferenceFormat::Hybrid,
    default => throw new InvalidArgumentException('Unknown cross-reference format.'),
};
$first = new DocumentPart(new DocumentPartMetadata(new Entry(new NameType('State'), new NameType('Révisé'))));
$second = new DocumentPart(new DocumentPartMetadata(new Entry(new NameType('State'), new NameType('Ready'))));
$parent = (new DocumentPart())->addChildren($first)->addChildren($second);
$root = new DocumentPartRoot($parent, new IntegerType(1), new NameType('Job'), new NameType('Customer'));
$pages = new PageTreeNode();
$owned = [];
foreach ([$first, $first, $second] as $part) {
    $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, new DocumentPartEntry($part));
    $pages->addKid($page);
    $owned[] = $page;
}
$first->setPageRange($owned[0], $owned[1]);
$second->setPageRange($owned[2]);
$catalog = new DocumentCatalog($pages, $root->catalogEntry());
file_put_contents($output, (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format))->toPdf());
