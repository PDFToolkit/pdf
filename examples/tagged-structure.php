<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Structure\AttributeAssociation;
use PDF\Document\Structure\AttributeClass;
use PDF\Document\Structure\AttributeClassAssociation;
use PDF\Document\Structure\AttributeClassAssociations;
use PDF\Document\Structure\ClassMap;
use PDF\Document\Structure\MarkInformation;
use PDF\Document\Structure\MarkedContentContainer;
use PDF\Document\Structure\MarkedContentIdentifier;
use PDF\Document\Structure\MarkedContentReference;
use PDF\Document\Structure\ObjectReference;
use PDF\Document\Structure\PageMarkedContent;
use PDF\Document\Structure\PageContentSequence;
use PDF\Document\Structure\RoleMap;
use PDF\Document\Structure\StandardStructureType;
use PDF\Document\Structure\StructureAttributeObject;
use PDF\Document\Structure\StructureAttributes;
use PDF\Document\Structure\StructureElement;
use PDF\Document\Structure\StructureTreeRoot;
use PDF\FileStructure;
use PDF\Specification\Dictionary\LinkAnnotationTable173;
use PDF\Specification\Dictionary\URIActionTable206;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Lang;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// ISO 32000-1, 14.7–14.8 : la structure logique et le dessin de chaque page
// sont deux graphes distincts. Leurs associations restent des objets explicites.
$font = new Type1Font(StandardFont::Helvetica, new Entry(new NameType('Encoding'), new NameType('WinAnsiEncoding')));
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
$box = new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(612), new IntegerType(792));

// Ces fonctions locales dessinent seulement du texte. Le balisage est fourni
// séparément par les objets MCID : aucun moteur de mise en page ne décide du sens.
/** @return list<Operator> */
$line = static fn (string $text, int $y, int $size = 14): array => [
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new IntegerType($size)),
    new MoveTextPosition(new IntegerType(64), new IntegerType($y)),
    new ShowText(new LiteralString($text)),
    new EndTextObject(),
];
/** @return list<Operator> */
$footer = static fn (string $text): array => [
    new BeginMarkedContent(new NameType('Artifact')),
    ...$line($text, 40, 10),
    new EndMarkedContent(),
];

$headingId = new MarkedContentIdentifier(new IntegerType(0));
$paragraphStartId = new MarkedContentIdentifier(new IntegerType(1));
$paragraphEndId = new MarkedContentIdentifier(new IntegerType(0));
$headerId = new MarkedContentIdentifier(new IntegerType(1));
$cellId = new MarkedContentIdentifier(new IntegerType(2));
$linkId = new MarkedContentIdentifier(new IntegerType(0));

// L'identifiant MCID est local au contenu d'une page. MCID 0 peut donc être
// utilisé sur trois pages, grâce à trois clés StructParents différentes.
$page1Contents = new PageContentSequence(new ContentStreams(...[
    $headingId->begin(StandardStructureType::H1),
    ...$line('PDFToolkit : structure logique', 716, 24),
    new EndMarkedContent(),
    $paragraphStartId->begin(StandardStructureType::P),
    ...$line('Ce paragraphe commence sur la premiere page.', 660),
]), new ContentStreams(...[
    // Les deux flux Contents sont consideres comme un seul flux de page :
    // ce second fragment termine la sequence MCID 1 ouverte dans le premier.
    ...$line('Son ordre de lecture sera conserve sur la page suivante.', 632),
    new EndMarkedContent(),
    ...$footer('Page 1 - ce pied de page est un artefact'),
]));
$page1 = new PageObject($box, $resources, $page1Contents->contents(), new Entry(new NameType('StructParents'), new IntegerType(0)));
$page2 = new PageObject($box, $resources, new StreamObject(new ContentStreams(...[
    $paragraphEndId->begin(StandardStructureType::P),
    ...$line('Le meme paragraphe se termine ici, sur la deuxieme page.', 716),
    new EndMarkedContent(),
    $headerId->begin(StandardStructureType::TH),
    ...$line('Composant', 622, 17),
    new EndMarkedContent(),
    $cellId->begin(StandardStructureType::TD),
    ...$line('StructureTreeRoot', 580),
    new EndMarkedContent(),
    ...$footer('Page 2 - tableau avec association explicite de son en-tete'),
])), new Entry(new NameType('StructParents'), new IntegerType(1)));

// Le lien visible et sa zone interactive sont deux objets. OBJR les rattache
// au meme element Link ; StructParent est une quatrieme cle du ParentTree.
$annotation = new LinkAnnotationTable173(
    new Entry(new NameType('Type'), new NameType('Annot')),
    new Entry(new NameType('Subtype'), new NameType('Link')),
    new Entry(new NameType('Rect'), new Rectangle(new IntegerType(64), new IntegerType(650), new IntegerType(460), new IntegerType(676))),
    new Entry(new NameType('Border'), new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0))),
    new Entry(new NameType('StructParent'), new IntegerType(3)),
    new Entry(new NameType('A'), new URIActionTable206(
        new Entry(new NameType('S'), new NameType('URI')),
        new Entry(new NameType('URI'), new LiteralString('https://pdftoolkit.github.io/pdf/')),
    )),
);
$page3 = new PageObject($box, $resources, new StreamObject(new ContentStreams(...[
    $linkId->begin(StandardStructureType::Link),
    ...$line('Ouvrir la documentation PDFToolkit', 660, 18),
    new EndMarkedContent(),
    ...$footer('Page 3 - texte et annotation font partie du meme lien logique'),
])), new Entry(new NameType('StructParents'), new IntegerType(2)), new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($annotation))));
$pages = (new PageTreeNode())->addKid($page1)->addKid($page2)->addKid($page3);
$scope1 = new MarkedContentContainer($page1, new IntegerType(0));
$scope2 = new MarkedContentContainer($page2, new IntegerType(1));
$scope3 = new MarkedContentContainer($page3, new IntegerType(2));

// ClassMap partage une valeur d'attribut. Les revisions restent propres aux
// associations d'un element ; elles ne sont jamais placees dans l'attribut partage.
$normal = new AttributeClass(new NameType('Normal'), new StructureAttributeObject(new DictionaryType(
    new Entry(new NameType('O'), new NameType('Layout')),
    new Entry(new NameType('TextAlign'), new NameType('Start')),
)));
$root = new StructureTreeRoot($pages, new RoleMap(), new ClassMap($normal));
$document = new StructureElement(StandardStructureType::Document);
$heading = (new StructureElement(StandardStructureType::H1, $page1, new LiteralString('heading')))->addKid(new PageMarkedContent($scope1, $headingId));
$paragraph = new StructureElement(StandardStructureType::P, $page1, new LiteralString('paragraph'), classes: new AttributeClassAssociations(new AttributeClassAssociation(new NameType('Normal'))));
$paragraph->addKid(new PageMarkedContent($scope1, $paragraphStartId));
// La forme entiere MCR indique la seconde page sans changer le Pg du paragraphe.
$paragraph->addKid(new MarkedContentReference($scope2, $paragraphEndId));
$table = new StructureElement(StandardStructureType::Table, $page2, new LiteralString('components'), new StructureAttributes(
    new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType('Layout')), new Entry(new NameType('BBox'), new Rectangle(new IntegerType(64), new IntegerType(575), new IntegerType(450), new IntegerType(645)))))),
    new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType('Table')), new Entry(new NameType('Summary'), new TextString('Liste des composants PDF'))))),
));
$header = new StructureElement(StandardStructureType::TH, $page2, new LiteralString('component-header'), new StructureAttributes(new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType('Table')), new Entry(new NameType('Scope'), new NameType('Column')))))));
$header->addKid(new PageMarkedContent($scope2, $headerId));
$cell = new StructureElement(StandardStructureType::TD, $page2, attributes: new StructureAttributes(new AttributeAssociation(new StructureAttributeObject(new DictionaryType(new Entry(new NameType('O'), new NameType('Table')), new Entry(new NameType('Headers'), new ArrayType(new LiteralString('component-header'))))))));
$cell->addKid(new PageMarkedContent($scope2, $cellId));
$table->addKid((new StructureElement(StandardStructureType::TR))->addKid($header));
$table->addKid((new StructureElement(StandardStructureType::TR))->addKid($cell));
$link = (new StructureElement(StandardStructureType::Link, $page3, new LiteralString('documentation-link')))->addKid(new PageMarkedContent($scope3, $linkId))->addKid(new ObjectReference($page3, $annotation, new IntegerType(3)));
$root->addKid($document->addKid($heading)->addKid($paragraph)->addKid($table)->addKid($link));

// Cette validation construit P/K, IDTree et ParentTree, controle leurs liens,
// puis verifie les conventions structurelles Tagged PDF. Le choix des mots,
// de leur Unicode, de la disposition et de l'ordre de lecture reste explicite.
$catalog = new DocumentCatalog($pages, new Lang(new TextString('fr-FR')), ...$root->catalogEntries(new MarkInformation(new BooleanType(true))));
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/tagged-structure.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
