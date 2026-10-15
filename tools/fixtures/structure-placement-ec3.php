<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\ResourceDictionary;
use PDF\Document\Structure\AttributeAssociation;
use PDF\Document\Structure\MarkInformation;
use PDF\Document\Structure\StandardStructureType;
use PDF\Document\Structure\StructureAttributeObject;
use PDF\Document\Structure\StructureAttributes;
use PDF\Document\Structure\StructureAttributeSemantics;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;

$output = $argv[1] ?? throw new InvalidArgumentException('An output path is required.');
$format = match ($argv[2] ?? 'table') {
    'table' => CrossReferenceFormat::Table,
    'stream' => CrossReferenceFormat::Stream,
    'hybrid' => CrossReferenceFormat::Hybrid,
    default => throw new InvalidArgumentException('Unknown cross-reference format.'),
};
$pages = (new PageTreeNode())->addKid(new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(10), new IntegerType(10)), new ResourceDictionary()));
$root = new StructureTreeRoot($pages, attributeSemantics: StructureAttributeSemantics::Pdf20);
$document = new StructureElement(StandardStructureType::Document);
foreach (['P' => 'Width', 'Sect' => 'Height'] as $role => $dimension) {
    $attributes = new StructureAttributes(new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType('Layout')), new Entry(new NameType($dimension), new IntegerType(10))))));
    $figure = new StructureElement(StandardStructureType::Figure, attributes: $attributes, entries: new Entry(new NameType('Alt'), new LiteralString('Structure attribute fixture, without rendered content.')));
    $document->addKid((new StructureElement(new NameType($role)))->addKid($figure));
}
$root->addKid($document);
$catalog = new DocumentCatalog($pages, ...$root->catalogEntries(new MarkInformation(new BooleanType(true))));
file_put_contents($output, (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format))->toPdf());
