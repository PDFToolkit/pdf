<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\Extensions\DeveloperExtension;
use PDF\Document\Extensions\DeveloperExtensionEntry;
use PDF\Document\Extensions\DeveloperExtensions;
use PDF\Document\Extensions\DeveloperPrefix;
use PDF\Document\Fonts\FontDescriptor;
use PDF\Document\Fonts\FontDescriptorFlags;
use PDF\Document\Fonts\SimpleFontMetrics;
use PDF\Document\Fonts\SimpleWidths;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Graphics\OptionalContent\Configuration;
use PDF\Graphics\OptionalContent\GroupCollection;
use PDF\Graphics\OptionalContent\OptionalContentGroup;
use PDF\Graphics\OptionalContent\OptionalContentProperties;
use PDF\Graphics\OptionalContent\RadioButtonGroups;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;

$output = $argv[1] ?? throw new InvalidArgumentException('An output file is required.');
$format = match ($argv[2] ?? 'table') {
    'table' => CrossReferenceFormat::Table,
    'stream' => CrossReferenceFormat::Stream,
    'hybrid' => CrossReferenceFormat::Hybrid,
    default => throw new InvalidArgumentException('Unknown cross-reference format.'),
};
$rectangle = new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100));
$descriptor = new FontDescriptor(null, new NameType('ExampleFont'), new FontDescriptorFlags(32), $rectangle, new NumericType(0), new NumericType(700), new NumericType(-200), new NumericType(700), new NumericType(80), new Entry(new NameType('FontWeight'), new NumericType(450.5)));
$font = new Type1Font(new NameType('ExampleFont'), new SimpleFontMetrics(new SimpleWidths(new IntegerType(65), new NumericType(600)), $descriptor));
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
$pages = (new PageTreeNode())->addKid(new PageObject($rectangle, $resources));
$a = new OptionalContentGroup(new TextString('Choice A'));
$b = new OptionalContentGroup(new TextString('Choice B'));
$groups = new GroupCollection($a, $b);
$optional = new OptionalContentProperties($groups, new Configuration(radioGroups: new RadioButtonGroups($groups)), new Configuration(radioGroups: new RadioButtonGroups()));
$extension = new DeveloperExtension(new NameType('2.0'), new IntegerType(1), new Entry(new NameType('URL'), new LiteralString('https://example.org/pdf-extension')));
$catalog = new DocumentCatalog($pages, $optional->catalogEntry(), new Entry(new NameType('Extensions'), new DeveloperExtensions(new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('EXAM')), $extension))));
file_put_contents($output, (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format))->toPdf());
