<?php

declare(strict_types=1);

use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

require $argv[2] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';
$directory = $argv[1] ?? throw new InvalidArgumentException('Supply a fixture output directory.');
if (!is_dir($directory)) {
    throw new InvalidArgumentException('The fixture output directory must already exist.');
}

function appearanceEntry(string $name, BasicType $value): Entry
{
    return new Entry(new NameType($name), $value);
}

/** @param list<int> $values */
function appearanceBox(array $values): Rectangle
{
    return new Rectangle(...array_map(static fn (int $value): IntegerType => new IntegerType($value), $values));
}

function appearanceForm(): FormAppearanceStream
{
    return new FormAppearanceStream(new ContentStreams(
        new SetNonstrokingRGBColor(new IntegerType(0), new IntegerType(1), new IntegerType(0)),
        new AppendRectangle(new IntegerType(0), new IntegerType(0), new IntegerType(20), new IntegerType(20)),
        new FillPathNonzero(),
    ), appearanceBox([0, 0, 20, 20]), new DictionaryType());
}

function appearanceAnnotation(Entry ...$entries): DictionaryType
{
    return new DictionaryType(appearanceEntry('Subtype', new NameType('Square')), appearanceEntry('Rect', appearanceBox([20, 20, 80, 70])), ...$entries);
}

function appearanceCatalog(DictionaryType $annotation): DocumentCatalog
{
    $page = new PageObject(appearanceBox([0, 0, 100, 100]), new ResourceDictionary(), null, appearanceEntry('Annots', new ArrayType(new IndirectReference($annotation))));
    return new DocumentCatalog((new PageTreeNode())->addKid($page));
}

function appearanceFile(DictionaryType $annotation, string $version = '2.0'): FileStructure
{
    return new FileStructure(new FileHeader($version), new FileBody(appearanceCatalog($annotation)));
}

$normal = new DictionaryType(appearanceEntry('N', new IndirectReference(appearanceForm())));
$states = new DictionaryType(appearanceEntry('N', new DictionaryType(appearanceEntry('On', new IndirectReference(appearanceForm())))));
$files = [
    'pdf17-optional' => [appearanceFile(appearanceAnnotation(), '1.7'), false],
    'pdf20-normal' => [appearanceFile(appearanceAnnotation(appearanceEntry('AP', $normal))), true],
    'pdf20-reversed' => [appearanceFile(appearanceAnnotation(appearanceEntry('AP', $normal))->with(appearanceEntry('Rect', appearanceBox([80, 70, 20, 20])))), true],
    'pdf20-named' => [appearanceFile(appearanceAnnotation(appearanceEntry('AP', $states), appearanceEntry('AS', new NameType('On')))), true],
    'pdf20-undefined' => [appearanceFile(appearanceAnnotation(appearanceEntry('AP', $states), appearanceEntry('AS', new NameType('Missing')))), false],
    'pdf20-point' => [appearanceFile(appearanceAnnotation()->with(appearanceEntry('Rect', appearanceBox([42, 17, 42, 17])))), false],
    'pdf20-link' => [appearanceFile(appearanceAnnotation()->with(appearanceEntry('Subtype', new NameType('Link')))), false],
];
$manifest = [];
foreach ($files as $name => [$file, $painted]) {
    $file->writeTo($directory . '/' . $name . '.pdf');
    $manifest[] = ['file' => $name . '.pdf', 'greenAppearance' => $painted];
}
$rejected = [];
$invalid = [
    'missing-ap' => appearanceAnnotation(),
    'null-ap' => appearanceAnnotation(appearanceEntry('AP', new NullType())),
    'line-rectangle' => appearanceAnnotation()->with(appearanceEntry('Rect', appearanceBox([20, 20, 20, 70]))),
    'reversed-line' => appearanceAnnotation()->with(appearanceEntry('Rect', appearanceBox([80, 20, 20, 20]))),
    'missing-normal' => appearanceAnnotation(appearanceEntry('AP', new DictionaryType())),
    'missing-state' => appearanceAnnotation(appearanceEntry('AP', $states)),
    'scalar-state' => appearanceAnnotation(appearanceEntry('AP', $states), appearanceEntry('AS', new IntegerType(1))),
    'nonstream-state' => appearanceAnnotation(appearanceEntry('AP', new DictionaryType(appearanceEntry('N', new DictionaryType(appearanceEntry('On', new IntegerType(1)))))), appearanceEntry('AS', new NameType('On'))),
];
foreach ($invalid as $name => $annotation) {
    try {
        appearanceFile($annotation)->toRevision();
        $rejected[$name] = false;
    } catch (InvalidArgumentException|LogicException) {
        $rejected[$name] = true;
    }
}
$legacyAnnotation = appearanceAnnotation();
$catalog = appearanceCatalog($legacyAnnotation);
$base = (new FileStructure(new FileHeader('1.7'), new FileBody($catalog)))->toRevision();
$upgrade = $base->binding($catalog)->dictionary->with(appearanceEntry('Version', new NameType('2.0')));
try {
    (new IncrementalUpdate($base, new FileBody(new IndirectObject($upgrade, $base->binding($catalog)->identifier))))->toRevision();
    $rejected['frozen-header17-upgrade'] = false;
} catch (InvalidArgumentException|LogicException) {
    $rejected['frozen-header17-upgrade'] = true;
}
$fixed = $base->binding($legacyAnnotation)->dictionary->with(appearanceEntry('AP', $normal));
(new IncrementalUpdate($base, new FileBody(new IndirectObject($upgrade, $base->binding($catalog)->identifier), new IndirectObject($fixed, $base->binding($legacyAnnotation)->identifier))))->writeTo($directory . '/pdf17-upgraded-fixed.pdf');
$manifest[] = ['file' => 'pdf17-upgraded-fixed.pdf', 'greenAppearance' => true];
file_put_contents($directory . '/native.json', json_encode(['files' => $manifest, 'rejected' => $rejected], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo json_encode(['pdfs' => count($manifest), 'rejected' => $rejected], JSON_THROW_ON_ERROR) . PHP_EOL;
