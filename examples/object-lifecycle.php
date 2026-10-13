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
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ReleaseIndirectObject;
use PDF\Syntax\FileStructure\ReuseObjectNumber;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// ISO 7.5.4 : un objet indirect possède un numéro ET une génération.
// L'objet facultatif ci-dessous sert à observer sa suppression et son recyclage.
$old = new LiteralString('Valeur indirecte initiale, generation 0.');
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
$page = new PageObject(
    new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(612), new IntegerType(792)),
    $resources,
    new StreamObject(new ContentStreams(
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
        new MoveTextPosition(new NumericType(50), new NumericType(720)),
        new ShowText(new LiteralString('Trois revisions, un numero et deux generations.')),
        new EndTextObject(),
    )),
);
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('PrivateOriginal'), new IndirectReference($old)));
$initial = (new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: CrossReferenceFormat::Stream))->toRevision();
$identifier = $initial->binding($old)->identifier;

// La suppression ne retire aucun octet historique. Elle inscrit le numéro dans
// la liste libre avec la génération suivante, ici 1. L'ancienne référence 0
// représente désormais null, conformément à ISO 7.3.9.
$released = (new IncrementalUpdate($initial, new FileBody(), objectOperations: new ReleaseIndirectObject($identifier)))->toRevision();

// Une nouvelle identité peut reprendre ce numéro avec sa génération libre.
// Son type est indépendant : l'ancienne chaîne devient ici un dictionnaire.
$new = new DictionaryType(new Entry(new NameType('Message'), new LiteralString('Nouvelle identite, generation 1.')));
$updatedCatalog = $released->binding($catalog)->dictionary ?? throw new LogicException('Le catalogue possède un instantané.');
$updatedCatalog = $updatedCatalog->with(new Entry(new NameType('PrivateCurrent'), new IndirectReference($new)));
$reused = (new IncrementalUpdate(
    $released,
    new FileBody(new IndirectObject($updatedCatalog, $initial->binding($catalog)->identifier)),
    objectOperations: new ReuseObjectNumber($identifier->objectNumber, $new),
))->toRevision();

// Les deux références restent distinctes malgré leur numéro commun. Le core
// garde les alias obsolètes pour écrire leur génération d'origine, sans les
// transformer en alias de la nouvelle valeur et sans ressusciter l'ancien objet.
$oldReference = $reused->reference($old);
$currentReference = $reused->reference($new);
$path = $argv[1] ?? dirname(__DIR__) . '/_build/pdfs/object-lifecycle.pdf';
$reused->writeTo($path);
echo $oldReference->identifier, ' R -> null ; ', $currentReference->identifier, ' R -> dictionnaire', PHP_EOL;
echo $path, PHP_EOL;
