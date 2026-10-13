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

// Tutoriel 08 : docs/tutorials/08-object-stream.md.
// On conserve le graphe du premier tutoriel et on change sa représentation
// dans le fichier : certains dictionnaires sont rangés dans un /ObjStm.

// 1. Préparer la police et les ressources de la page, comme au tutoriel 01.
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));
// 2. Construire le texte et compresser ses octets avec FlateDecode.
// Le filtre est nommé selon l'opération que le lecteur appliquera au décodage.
// Il ne compresse pas les autres objets du fichier : chaque flux a ses filtres.
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('An explicit object stream and cross-reference stream.')),
    new EndTextObject(),
), null, new FlateDecode());
// 3. Assembler la page, son arbre de pages et le catalogue.
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);

// 4. Choisir les objets qui seront membres du flux /ObjStm.
// Les numéros 40 à 43 sont réservés explicitement ; le writer évite les collisions
// lorsqu'il attribue les autres numéros. Chaque membre a une génération zéro.
// $contents, qui est lui-même un flux, reste un objet indirect hors du conteneur.
// Le second FlateDecode compresse le conteneur entier, indépendamment du contenu.
$objects = (new ObjectStream(
    new ObjectStreamEntry(new ObjectNumber(40), $catalog),
    new ObjectStreamEntry(new ObjectNumber(41), $pages),
    new ObjectStreamEntry(new ObjectNumber(42), $page),
    new ObjectStreamEntry(new ObjectNumber(43), $font),
))->withFilters(new FlateDecode());

// 5. Sélectionner un flux de références croisées (/Type /XRef).
// Ses entrées de type 2 indiquent le conteneur et l'index des objets compressés.
// /Root vise toujours le catalogue, même si celui-ci se trouve dans /ObjStm.
// Ces deux représentations existent à partir de PDF 1.5 ; notre header est 1.7.
$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($objects),
    new IndirectReference($catalog),
    null,
    CrossReferenceFormat::Stream,
);
// 6. Écrire le PDF. Le rendu de la page dépend de son contenu et de ses ressources,
// tandis que /ObjStm et /XRef organisent les objets dans les octets du fichier.
$path = $argv[1] ?? __DIR__ . '/object-stream.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
