<?php

declare(strict_types=1);

use PDF\Document\Redaction\RedactionAnnotation;
use PDF\Document\Redaction\RedactionPageRequest;
use PDF\Document\Redaction\RedactionPlan;
use PDF\Document\Redaction\SecureRedaction;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

require dirname(__DIR__).'/vendor/autoload.php';

$directory = $argv[1] ?? throw new InvalidArgumentException('Supply an explicit fixture output directory.');
$cases = [
    [[1, 1, -1, 1, 50, 20], [0, 0, 20, 20], [59, 39, 61, 41]],
    [[0.70710678, 0.70710678, -0.70710678, 0.70710678, 80, 20], [0, 0, 20, 20], [89, 33, 91, 35]],
    [[1, 1, -1, 1, 50, 20], [20, 0, -20, 20], [49, 29, 51, 31]],
    [[2, 1, -3, 4, 80, 10], [0, 0, 20, 10], [108, 29, 110, 31]],
];
$tree = new PageTreeNode();
$requests = [];
foreach ($cases as [$matrix, $rectangle, $region]) {
    $numbers = static fn (array $values): array => array_map(static fn (int|float $value): NumericType => new NumericType($value), $values);
    $content = new ContentStreams(
        new SaveGraphicsState(), new ConcatenateMatrix(new Matrix(...$numbers($matrix))),
        new AppendRectangle(...$numbers($rectangle)), new FillPathNonzero(), new RestoreGraphicsState(),
        new SetNonstrokingRGBColor(new NumericType(0), new NumericType(1), new NumericType(0)),
        new AppendRectangle(new NumericType(150), new NumericType(10), new NumericType(10), new NumericType(10)), new FillPathNonzero(),
    );
    $page = new PageObject(new Rectangle(...$numbers([0, 0, 200, 120])), new ResourceDictionary(), new StreamObject($content));
    $tree->addKid($page);
    $annotation = new RedactionAnnotation(new DictionaryType(new Entry(new NameType('Subtype'), new NameType('Redact')), new Entry(new NameType('Rect'), new ArrayType(...$numbers($region)))));
    $requests[] = new RedactionPageRequest($page, $annotation);
}
$catalog = new DocumentCatalog($tree);
$source = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$plan = new RedactionPlan(...$requests);
$redacted = (new SecureRedaction())->apply($catalog, $plan);
file_put_contents($directory.'/transforms-original.pdf', $source->toPdf());
file_put_contents($directory.'/transforms-redacted.pdf', $redacted->toPdf());
echo json_encode(['cases' => count($cases), 'removedPaintObjects' => $redacted->removedPaintObjects], JSON_THROW_ON_ERROR), "\n";
