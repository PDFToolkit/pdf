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

require dirname(__DIR__) . '/vendor/autoload.php';

// Tutorial 08: docs/tutorials/08-object-stream.md.
// Keep the first tutorial's graph and change its file representation:
// some dictionaries are stored inside an /ObjStm.

// 1. Prepare the font and page resources, as in tutorial 01.
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));
// 2. Build text and compress its bytes with FlateDecode.
// The filter is named for the operation readers apply when decoding.
// It does not compress other file objects: each stream has its own filters.
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('An explicit object stream and cross-reference stream.')),
    new EndTextObject(),
), null, new FlateDecode());
// 3. Assemble the page, its page tree and catalog.
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);

// 4. Choose objects that will be members of the /ObjStm stream.
// Numbers 40 to 43 are explicitly reserved; the writer avoids collisions
// when assigning other numbers. Every member has generation zero.
// $contents, itself a stream, remains an indirect object outside the container.
// The second FlateDecode compresses the whole container independently of content.
$objects = (new ObjectStream(
    new ObjectStreamEntry(new ObjectNumber(40), $catalog),
    new ObjectStreamEntry(new ObjectNumber(41), $pages),
    new ObjectStreamEntry(new ObjectNumber(42), $page),
    new ObjectStreamEntry(new ObjectNumber(43), $font),
))->withFilters(new FlateDecode());

// 5. Select a cross-reference stream (/Type /XRef).
// Its type 2 entries identify the container and compressed-object indices.
// /Root still targets the catalog even when it is inside /ObjStm.
// Both representations have existed since PDF 1.5; our header is 1.7.
$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($objects),
    new IndirectReference($catalog),
    null,
    CrossReferenceFormat::Stream,
);
// 6. Write the PDF. Page rendering depends on content and resources,
// while /ObjStm and /XRef organize objects within the file's bytes.
$path = $argv[1] ?? __DIR__ . '/object-stream.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
