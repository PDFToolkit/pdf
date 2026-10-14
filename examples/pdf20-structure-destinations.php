<?php

declare(strict_types=1);

use PDF\Document\Destinations\Fit;
use PDF\Document\Fonts as Fonts;
use PDF\Document\Navigation as Navigation;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Structure as Structure;
use PDF\FileStructure;
use PDF\Fonts\TrueType as TrueType;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// ISO 32000-2 12.3.2.3 pp.458-459 and Table202 p.526: semantic identity
// and a physical page fallback are deliberately composed as separate objects.
$text = [
    ['PDF 2.0: structure destinations', 'Both links target the logical section on page 2.', 'Follow the explicit structure destination', 'Follow the named structure destination', 'D remains an explicit page fallback beside SD.'],
    ['Semantic target: the section on page 2', 'Content references determine the target page.', 'An empty earlier child with Pg is skipped.', 'An entirely empty target falls back to page 1.'],
];

// The optional converter prepares a real embedded program and mappings. Every
// resulting font, descriptor, CID width and ToUnicode PDF object remains explicit.
$fontBytes = file_get_contents(dirname(__DIR__) . '/vendor/pdftoolkit/pdf-fonts/examples/assets/fonts/NotoSans-Regular.ttf');
if ($fontBytes === false) {
    throw new RuntimeException('Unable to read the demonstration font.');
}
$program = new TrueType\CIDFontProgram(new TrueType\FontProgram($fontBytes));
$fontFile = new Fonts\FontFile2($program->bytes, new FlateDecode());
$map = (new TrueType\CharacterMapFactory($program))->create($fontFile, new TrueType\UnicodeText(implode('', [...$text[0], ...$text[1]])));
$encoder = new TrueType\TextEncoder($map);
$descriptor = (new TrueType\FontDescriptorFactory($program))->create($fontFile, new Fonts\FontDescriptorFlags(4), new NumericType(80));
$font = new Fonts\Type0Font(new Fonts\CIDFontType2($descriptor, new Fonts\CIDToGIDMap($map), new Fonts\CIDWidths($map)), new Fonts\ToUnicodeCMap($map));
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
$namespace = new Structure\StructureNamespace(Structure\StandardStructureNamespace::PDF20);
$role = static fn (string $name): Structure\StructureRole => new Structure\StructureRole(new NameType($name), $namespace);
$content = static function (array $lines, bool $links) use ($encoder): StreamObject {
    $operators = [];
    foreach (array_values($lines) as $index => $line) {
        $operators[] = (new Structure\MarkedContentIdentifier(new IntegerType($index)))->begin(new NameType($index === 0 ? 'H1' : ($links && in_array($index, [2, 3], true) ? 'Link' : 'P')));
        $operators[] = new Op\BeginTextObject();
        $operators[] = new Op\SetTextFontAndSize(new NameType('F1'), new NumericType($index === 0 ? 20 : 12));
        $operators[] = new Op\MoveTextPosition(new NumericType(48), new NumericType(760 - 42 * $index));
        $operators[] = new Op\ShowText($encoder->encode(new TrueType\UnicodeText($line)));
        $operators[] = new Op\EndTextObject();
        $operators[] = new Op\EndMarkedContent();
    }
    return new StreamObject(new ContentStreams(...$operators));
};
$box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842));
$second = new PageObject($box, $resources, $content($text[1], false), new Entry(new NameType('StructParents'), new IntegerType(1)));
$section = new Structure\StructureElement($role('Sect'));
$view = new Navigation\DestinationView(new NameType('Fit'));
$destination = new Navigation\StructureDestination($section, $view);
$pageFallback = new Navigation\LocalDestination(new Fit($second));
$explicitAction = new Navigation\GoToDestination($pageFallback, structure: $destination);
$namedAction = new Navigation\GoToDestination(new Navigation\NamedDestination(new LiteralString('target-section')));
$annotations = [];
foreach ([$explicitAction, $namedAction] as $position => $action) {
    $annotations[] = new DictionaryType(
        new Entry(new NameType('Type'), new NameType('Annot')),
        new Entry(new NameType('Subtype'), new NameType('Link')),
        new Entry(new NameType('Rect'), new Rectangle(new NumericType(44), new NumericType(670 - 42 * $position), new NumericType(400), new NumericType(692 - 42 * $position))),
        new Entry(new NameType('Border'), new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(1))),
        new Entry(new NameType('Contents'), new TextString('Open the semantic section on page 2.')),
        new Entry(new NameType('StructParent'), new IntegerType(100 + $position)),
        new Entry(new NameType('A'), $action),
    );
}
$first = new PageObject($box, $resources, $content($text[0], true), new Entry(new NameType('StructParents'), new IntegerType(0)), new Entry(new NameType('Annots'), new ArrayType(...array_map(static fn (DictionaryType $annotation): IndirectReference => new IndirectReference($annotation), $annotations))), new Entry(new NameType('Tabs'), new NameType('S')));
$pages = (new PageTreeNode())->addKid($first)->addKid($second);
$root = new Structure\StructureTreeRoot($pages, namespaces: new Structure\StructureNamespaces($namespace), inclusionRules: new Structure\StructureInclusionRules());
$document = new Structure\StructureElement($role('Document'));
$root->addKid($document);
$intro = new Structure\StructureElement($role('Sect'));
$document->addKid($intro)->addKid($section);
// An empty Pg entry is intentionally insufficient: the resolver skips this child.
$section->addKid(new Structure\StructureElement($role('P'), page: $first));
foreach ([[$first, $intro, 0], [$second, $section, 1]] as [$page, $parent, $key]) {
    $container = new Structure\MarkedContentContainer($page, new IntegerType($key));
    foreach ($text[$key] as $index => $_line) {
        $link = $key === 0 && in_array($index, [2, 3], true);
        $element = new Structure\StructureElement($role($index === 0 ? 'H1' : ($link ? 'Link' : 'P')), page: $page);
        $element->addKid(new Structure\PageMarkedContent($container, new Structure\MarkedContentIdentifier(new IntegerType($index))));
        if ($link) {
            $element->addKid(new Structure\ObjectReference($page, $annotations[$index - 2], new IntegerType(100 + $index - 2)));
        }
        $parent->addKid($element);
    }
}
$empty = new Structure\StructureElement($role('Sect'), page: $second);
$document->addKid($empty);
$named = new Navigation\NamedDestinations(
    new Navigation\NamedDestinationEntry(new LiteralString('target-section'), new Navigation\DestinationDictionary($pageFallback, new Navigation\StructureDestinationEntry($destination))),
    new Navigation\NamedDestinationEntry(new LiteralString('empty-section'), new Navigation\DestinationDictionary(new Navigation\LocalDestination(new Fit($first)), new Navigation\StructureDestinationEntry(new Navigation\StructureDestination($empty, $view)))),
);
$catalog = new DocumentCatalog($pages, ...[...$root->catalogEntries(new Structure\MarkInformation(new BooleanType(true))), $named->catalogEntry(), new Entry(new NameType('Lang'), new TextString('en-US'))]);
$format = in_array('--xref-stream', $argv ?? [], true) ? CrossReferenceFormat::Stream : (in_array('--hybrid', $argv ?? [], true) ? CrossReferenceFormat::Hybrid : CrossReferenceFormat::Table);
$file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog), new IndirectReference($catalog), crossReferenceFormat: $format);
$path = $argv[1] ?? __DIR__ . '/pdf20-structure-destinations.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
