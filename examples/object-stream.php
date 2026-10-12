<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/tests/bootstrap.php';

$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('An explicit object stream and cross-reference stream.')),
    new EndTextObject(),
), null, new FlateDecode());
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);

// Reserve explicit member numbers. Each member is generation zero; stream
// objects themselves remain ordinary indirect objects outside this container.
$objects = (new ObjectStream(
    new ObjectStreamEntry(new ObjectNumber(40), $catalog),
    new ObjectStreamEntry(new ObjectNumber(41), $pages),
    new ObjectStreamEntry(new ObjectNumber(42), $page),
    new ObjectStreamEntry(new ObjectNumber(43), $font),
))->withFilters(new FlateDecode());

$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($objects),
    new IndirectReference($catalog),
    null,
    CrossReferenceFormat::Stream,
);
$path = $argv[1] ?? __DIR__ . '/object-stream.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
