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
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = $argv[1] ?? throw new InvalidArgumentException('Supply an explicit fixture output directory.');
$dictionary = static fn (array $values): DictionaryType => new DictionaryType(...array_map(static fn (string $name, $value): Entry => new Entry(new NameType($name), $value), array_keys($values), array_values($values)));
$rectangle = static fn (array $values): Rectangle => new Rectangle(...array_map(static fn (int $value): NumericType => new NumericType($value), $values));
$paint = static fn (string $name, int $x, int $y): array => [new SaveGraphicsState(), new ConcatenateMatrix(new Matrix(new NumericType(10), d: new NumericType(10), e: new NumericType($x), f: new NumericType($y))), new InvokeXObject(new NameType($name)), new RestoreGraphicsState()];
$tree = new PageTreeNode();
$requests = [];
foreach (['Mask', 'SMask'] as $key) {
    $mask = new StreamObject('SOURCESECRET', $dictionary(['Type' => new NameType('XObject'), 'Subtype' => new NameType('Image'), 'Width' => new NumericType($key === 'Mask' ? 96 : 12), 'Height' => new NumericType(1), 'BitsPerComponent' => new NumericType($key === 'Mask' ? 1 : 8), 'ColorSpace' => new NameType('DeviceGray'), 'ImageMask' => new BooleanType($key === 'Mask')]));
    $pixels = $dictionary(['Type' => new NameType('XObject'), 'Subtype' => new NameType('Image'), 'Width' => new NumericType(3), 'Height' => new NumericType(1), 'BitsPerComponent' => new NumericType(8), 'ColorSpace' => new NameType('DeviceRGB')]);
    $dependent = new StreamObject('DEPENDENT', $pixels->with(new Entry(new NameType($key), new IndirectReference($mask))));
    $formResources = new ResourceDictionary(new Entry(new NameType('XObject'), $dictionary(['Dependent' => new IndirectReference($dependent)])));
    $form = new StreamObject(new ContentStreams(new InvokeXObject(new NameType('Dependent'))), $dictionary(['Type' => new NameType('XObject'), 'Subtype' => new NameType('Form'), 'BBox' => $rectangle([0, 0, 1, 1]), 'Resources' => $formResources]));
    $resources = new ResourceDictionary(new Entry(new NameType('XObject'), $dictionary(['Secret' => new IndirectReference($mask), 'Dependent' => new IndirectReference($dependent), 'Wrapper' => new IndirectReference($form), 'Public' => new IndirectReference(new StreamObject('PUBLICIMG', $pixels))])));
    $first = new PageObject($rectangle([0, 0, 200, 120]), $resources, new StreamObject(new ContentStreams(...[...$paint('Dependent', 20, 20), ...$paint('Wrapper', 100, 20), ...$paint('Public', 150, 20)])));
    $second = new PageObject($rectangle([0, 0, 200, 120]), $resources, new StreamObject(new ContentStreams(...$paint('Secret', 20, 70))));
    $tree->addKid($first)->addKid($second);
    $requests[] = new RedactionPageRequest($second, new RedactionAnnotation($dictionary(['Subtype' => new NameType('Redact'), 'Rect' => $rectangle([19, 68, 45, 82])])));
}
$catalog = new DocumentCatalog($tree);
$source = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$result = (new SecureRedaction())->apply($catalog, new RedactionPlan(...$requests));
file_put_contents($directory . '/dependencies-original.pdf', $source->toPdf());
file_put_contents($directory . '/dependencies-redacted.pdf', $result->toPdf());
echo json_encode(['cases' => 2, 'removedSharedResources' => $result->removedSharedResources], JSON_THROW_ON_ERROR), "\n";
