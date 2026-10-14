<?php

declare(strict_types=1);

use PDF\Document\DocumentInformation;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Information\Title;
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
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// Tutorial 01: docs/tutorials/01-first-document.md.
// This file builds a graph of PDF objects, then delegates writing to the writer.

// 1. Declare a font and give it a local name in page resources.
// /F1 is the name used by Tf; /Helvetica is the standard font name.
// The reference targets the font instance. Its number is assigned during writing.
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
);
// 2. Describe text with PDF instructions: BT, Tf, Td, Tj, ET.
// BT/ET enclose the text object. Tf selects /F1 at 18 units; Td moves
// the text origin to (72, 720). In this page space, y increases upwards
// and a unit defaults to 1/72 inch. Tj receives bytes, here in ASCII.
// ContentStreams retains operators; StreamObject turns them into a PDF stream.
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('Super ! Le PDF est construit objet par objet.')),
    new EndTextObject(),
));
// 3. Assemble the page: /MediaBox is a rectangle [x_min y_min x_max y_max].
// 612 x 792 corresponds to Letter size. The page connects resources and content.
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
// 4. Attach the page to the /Pages tree, then connect its root to /Catalog.
// The graph calculates /Parent, /Kids and /Count; they are not filled manually.
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
// 5. Add a document title in /Info. TextString explicitly encodes this Unicode
// value; this string does not represent the glyphs displayed by Tj.
$information = new DocumentInformation(new Title(new TextString('Un PDF entièrement objet')));
$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($catalog),
    new IndirectReference($catalog),
    new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($information))),
);
// 6. Write the file. The writer traverses references, assigns numbers,
// measures stream bytes and produces the xref table and trailer.
// The first argument selects the destination from the CLI command.
$path = $argv[1] ?? __DIR__ . '/hello-world.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
