<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\Files as F;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;

$output = $argv[1] ?? throw new InvalidArgumentException('An output file is required.');
$format = match ($argv[2] ?? 'table') {
    'table' => CrossReferenceFormat::Table,
    'stream' => CrossReferenceFormat::Stream,
    'hybrid' => CrossReferenceFormat::Hybrid,
    default => throw new InvalidArgumentException('Unknown cross-reference format.'),
};
$script = new F\FileSpecification(new LiteralString('empty.js'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, new F\EmbeddedFileStream('// Deliberately empty script.', new NameType('application/javascript'))))));
$assets = new NameTree(new NameTreeEntry(new LiteralString('empty.js'), new IndirectReference($script)));
$annotations = [];
foreach ([1, 2] as $number) {
    $configuration = new DictionaryType(new Entry(new NameType('Type'), new NameType('RichMediaConfiguration')), new Entry(new NameType('Name'), new LiteralString('Configuration ' . $number)));
    $content = new DictionaryType(new Entry(new NameType('Configurations'), new ArrayType(new IndirectReference($configuration))), new Entry(new NameType('Assets'), new IndirectReference($assets)));
    $activation = new DictionaryType(new Entry(new NameType('Scripts'), new ArrayType(new IndirectReference($script))), new Entry(new NameType('Configuration'), new IndirectReference($configuration)));
    $settings = new DictionaryType(new Entry(new NameType('Activation'), $activation));
    $annotation = new DictionaryType(new Entry(new NameType('Type'), new NameType('Annot')), new Entry(new NameType('Subtype'), new NameType('RichMedia')), new Entry(new NameType('Rect'), new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0))), new Entry(new NameType('RichMediaContent'), new IndirectReference($content)), new Entry(new NameType('RichMediaSettings'), new IndirectReference($settings)));
    $annotations[] = new IndirectReference($annotation);
}
$page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, new Entry(new NameType('Annots'), new ArrayType(...$annotations)));
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
file_put_contents($output, (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format))->toPdf());
