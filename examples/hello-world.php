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

// Tutoriel 01 : docs/tutorials/01-first-document.md.
// Ce fichier construit un graphe d'objets PDF, puis confie son écriture au writer.

// 1. Déclarer une police et lui donner un nom local dans les ressources de page.
// /F1 est le nom utilisé par Tf ; /Helvetica est le nom de la police standard.
// La référence vise l'instance de police. Son numéro sera attribué à l'écriture.
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($helvetica)),
    )),
);
// 2. Décrire le texte avec des instructions PDF : BT, Tf, Td, Tj, ET.
// BT/ET encadrent l'objet texte. Tf sélectionne /F1 à 18 unités ; Td déplace
// l'origine du texte à (72, 720). Dans cet espace de page, y augmente vers le haut
// et une unité vaut par défaut 1/72 de pouce. Tj reçoit des octets, ici en ASCII.
// ContentStreams conserve les opérateurs ; StreamObject en fait un flux PDF.
$contents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(72), new NumericType(720)),
    new ShowText(new LiteralString('Super ! Le PDF est construit objet par objet.')),
    new EndTextObject(),
));
// 3. Assembler la page : /MediaBox est un rectangle [x_min y_min x_max y_max].
// 612 x 792 correspond au format Letter. La page relie ressources et contenu.
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
// 4. Attacher la page à l'arbre /Pages, puis relier sa racine au /Catalog.
// Le graphe calcule /Parent, /Kids et /Count ; on ne les remplit pas à la main.
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
// 5. Ajouter un titre documentaire dans /Info. TextString encode explicitement
// ce texte Unicode ; cette chaîne ne représente pas les glyphes affichés par Tj.
$information = new DocumentInformation(new Title(new TextString('Un PDF entièrement objet')));
$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($catalog),
    new IndirectReference($catalog),
    new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($information))),
);
// 6. Écrire le fichier. Le writer parcourt les références, attribue les numéros,
// mesure les octets des flux et produit la table xref ainsi que le trailer.
// Le premier argument permet de choisir la destination depuis la commande CLI.
$path = $argv[1] ?? __DIR__ . '/hello-world.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
