<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

$output = $argv[1] ?? throw new InvalidArgumentException('An output path is required.');
$format = match ($argv[2] ?? 'table') {
    'table' => CrossReferenceFormat::Table,
    'stream' => CrossReferenceFormat::Stream,
    'hybrid' => CrossReferenceFormat::Hybrid,
    default => throw new InvalidArgumentException('Unknown cross-reference format.'),
};
$empty = ($argv[3] ?? 'baseline') === 'empty' ? [new Op\StrokePath(), new Op\CloseAndStrokePath(), new Op\FillPathNonzero(), new Op\FillPathNonzeroObsolete(), new Op\FillPathEvenOdd(), new Op\FillAndStrokePathNonzero(), new Op\FillAndStrokePathEvenOdd(), new Op\CloseFillAndStrokePathNonzero(), new Op\CloseFillAndStrokePathEvenOdd(), new Op\EndPath()] : [];
$content = new ContentStreams(...$empty);
$content = $content->with(new Op\SetNonstrokingRGBColor(new NumericType(0.1), new NumericType(0.4), new NumericType(0.8)), new Op\AppendRectangle(new IntegerType(10), new IntegerType(10), new IntegerType(40), new IntegerType(40)), new Op\FillPathNonzero(), new Op\SetNonstrokingRGBColor(new IntegerType(1), new IntegerType(0), new IntegerType(0)), ...$empty);
$page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(60), new IntegerType(60)), new ResourceDictionary(), new StreamObject($content));
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
file_put_contents($output, (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format))->toPdf());
