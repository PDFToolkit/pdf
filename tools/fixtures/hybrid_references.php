<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\Destinations\Fit;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Linearization\HintStreamSplit;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

$directory = $argv[1] ?? throw new InvalidArgumentException('Pass an existing local output directory.');
if (!is_dir($directory) || !is_writable($directory)) {
    throw new InvalidArgumentException('The output directory must exist and be writable.');
}
$widths = new ArrayType(...array_fill(0, 256, new IntegerType(500)));
$descriptor = new DictionaryType(new Entry(new NameType('Type'), new NameType('FontDescriptor')), new Entry(new NameType('FontName'), new NameType('Helvetica')), new Entry(new NameType('Flags'), new IntegerType(32)), new Entry(new NameType('FontBBox'), new Rectangle(new IntegerType(0), new IntegerType(-207), new IntegerType(1000), new IntegerType(718))), new Entry(new NameType('ItalicAngle'), new IntegerType(0)), new Entry(new NameType('Ascent'), new IntegerType(718)), new Entry(new NameType('Descent'), new IntegerType(-207)), new Entry(new NameType('CapHeight'), new IntegerType(718)), new Entry(new NameType('StemV'), new IntegerType(88)));
$font = new DictionaryType(new Entry(new NameType('Type'), new NameType('Font')), new Entry(new NameType('Subtype'), new NameType('Type1')), new Entry(new NameType('BaseFont'), new NameType('Helvetica')), new Entry(new NameType('FirstChar'), new IntegerType(0)), new Entry(new NameType('LastChar'), new IntegerType(255)), new Entry(new NameType('Widths'), new IndirectReference($widths)), new Entry(new NameType('FontDescriptor'), new IndirectReference($descriptor)));
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
$pages = new PageTreeNode();
$page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(300)), new IndirectReference($resources), new StreamObject('BT /F1 12 Tf 20 250 Td (Hybrid) Tj ET'));
$pages->addKid($page);
$outline = new OutlineDictionary();
$outline->addItem(new OutlineItem(new TextString('Hybrid'), new Fit($page)));
$catalog = new DocumentCatalog($pages, new Entry(new NameType('Version'), new NameType('1.5')), new Entry(new NameType('Outlines'), new IndirectReference($outline)));
$parts = [];
foreach ([$font, $widths, $descriptor, $resources, $pages, $page, $catalog, $outline] as $index => $value) {
    $parts[] = new ObjectStreamEntry(new ObjectNumber(100 + $index), $value);
}
$container = new ObjectStream(...[...$parts, new FlateDecode()]);
$file = new FileStructure(new FileHeader('1.4'), new FileBody($catalog, $container), new IndirectReference($catalog), null, CrossReferenceFormat::Hybrid);
$initial = (new FileWriter())->revision($file);
file_put_contents($directory . '/initial.pdf', $initial->bytes);

$optional = new DictionaryType(new Entry(new NameType('Private'), new IntegerType(7)));
$memberNumber = new ObjectNumber($initial->size->integer() + 2);
$updatedCatalog = $initial->binding($initial->root->target())->dictionary?->with(new Entry(new NameType('PieceInfo'), new IndirectReference($optional)))
    ?? throw new LogicException('The source fixture has a catalog dictionary.');
$packed = new ObjectStream(new ObjectStreamEntry($memberNumber, $optional), new FlateDecode());
$update = (new IncrementalUpdate($initial, new FileBody(new IndirectObject($updatedCatalog, $initial->binding($initial->root->target())->identifier), $packed)))->toRevision();
file_put_contents($directory . '/update.pdf', $update->bytes);
$replacement = $optional->with(new Entry(new NameType('Private'), new IntegerType(8)));
$again = (new IncrementalUpdate($update, new FileBody(new IndirectObject($replacement, $update->binding($optional)->identifier))))->toRevision();
file_put_contents($directory . '/standalone-replacement.pdf', $again->bytes);
$catalogArray = $again->binding($again->root->target())->dictionary?->with(new Entry(new NameType('PrivateRequiredArray'), new ArrayType($again->reference($outline))))
    ?? throw new LogicException('The source fixture has a catalog dictionary.');
$visible = (new IncrementalUpdate($again, new FileBody(new IndirectObject($catalogArray, $again->binding($again->root->target())->identifier))))->toRevision();
file_put_contents($directory . '/became-visible.pdf', $visible->bytes);
file_put_contents($directory . '/linearized.pdf', (new LinearizedWriter())->serialize($file));
file_put_contents($directory . '/linearized-overflow.pdf', (new LinearizedWriter(hintSplit: new HintStreamSplit(new IntegerType(1))))->serialize($file));
$manifest = [
    ['file' => 'initial.pdf', 'previous' => null, 'outlineHidden' => true, 'pieceHidden' => false, 'pieceValue' => null, 'linearized' => false],
    ['file' => 'update.pdf', 'previous' => 'initial.pdf', 'outlineHidden' => true, 'pieceHidden' => true, 'pieceValue' => 7, 'linearized' => false],
    ['file' => 'standalone-replacement.pdf', 'previous' => 'update.pdf', 'outlineHidden' => true, 'pieceHidden' => false, 'pieceValue' => 8, 'linearized' => false],
    ['file' => 'became-visible.pdf', 'previous' => 'standalone-replacement.pdf', 'outlineHidden' => false, 'pieceHidden' => false, 'pieceValue' => 8, 'linearized' => false],
    ['file' => 'linearized.pdf', 'previous' => null, 'outlineHidden' => true, 'pieceHidden' => false, 'pieceValue' => null, 'linearized' => true],
    ['file' => 'linearized-overflow.pdf', 'previous' => null, 'outlineHidden' => true, 'pieceHidden' => false, 'pieceValue' => null, 'linearized' => true],
];
file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
echo count($manifest), ' native hybrid-reference files', PHP_EOL;
