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
use PDF\Syntax\ContentStreamsResources\Operators\MoveToNextTextLine;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextLeading;
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

// 1. All three pages use the same font and resource instances.
// F1/F2 are local Resource dictionary names, not font names.
$regular = new Type1Font(StandardFont::Helvetica);
$bold = new Type1Font(StandardFont::HelveticaBold);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(
        new Entry(new NameType('F1'), new IndirectReference($regular)),
        new Entry(new NameType('F2'), new IndirectReference($bold)),
    )),
);

// 2. MediaBox and Resources are inherited by children of the Pages node.
// A unit is 1/72 inch here: 595.28 × 841.89 corresponds to A4.
$pages = new PageTreeNode(
    new Entry(new NameType('MediaBox'), new Rectangle(
        new NumericType(0),
        new NumericType(0),
        new NumericType(595.28),
        new NumericType(841.89),
    )),
    new Entry(new NameType('Resources'), new IndirectReference($resources)),
);

// These PHP data describe demonstration content, not a layout API.
// ASCII bytes suit the encodings of these standard fonts.
$chapters = [
    [
        'title' => '01 / Une page, un repere',
        'subtitle' => 'Coordonnees explicites et objets de texte',
        'lines' => [
            'L\'origine (0, 0) est en bas a gauche.',
            'La premiere ligne commence en (54, 692).',
            'Le titre est place separement en (54, 778).',
            'Une unite vaut 1/72 de pouce dans ce document.',
            '',
            'BT ouvre un objet de texte ; ET le termine.',
            'Tf choisit une ressource de police et une taille.',
            'Td deplace la position de la ligne de texte.',
            'Tj dessine les glyphes des octets de la chaine.',
            '',
            'Aucun placement n\'est calcule automatiquement.',
        ],
    ],
    [
        'title' => '02 / Des lignes explicites',
        'subtitle' => 'Interligne et matrices de texte',
        'lines' => [
            'Le corps utilise Helvetica en taille 13.',
            'TL fixe un interligne de 22 unites.',
            'T* descend d\'un interligne et revient au debut.',
            'Chaque ligne est un nouvel operateur Tj.',
            '',
            'Cette ligne vide occupe aussi un interligne.',
            'Un caractere de nouvelle ligne dans une chaine',
            'ne remplace pas ces instructions PDF.',
            '',
            'Le pied de page est un autre objet de texte.',
            'Son numero est compose explicitement en PHP.',
        ],
    ],
    [
        'title' => '03 / Un arbre commun',
        'subtitle' => 'Pages autonomes, ressources partagees',
        'lines' => [
            'Chaque page possede son propre flux Contents.',
            'Les trois pages appartiennent au meme noeud Pages.',
            'Le noeud porte le format A4 et les ressources.',
            'Ses enfants heritent de MediaBox et Resources.',
            '',
            'F1 et F2 referencent les memes objets de police.',
            'Le serialiseur attribue leurs numeros une seule fois.',
            'Il produit aussi Kids, Parent et le nombre de pages.',
            '',
            'Le titre des metadonnees peut etre Unicode.',
            'Les chaines dessinees restent des octets de glyphes.',
        ],
    ],
];

// 3. Each BT/ET sequence starts again with identity text matrices.
foreach ($chapters as $index => $chapter) {
    $contents = new ContentStreams(
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F2'), new NumericType(24)),
        new MoveTextPosition(new NumericType(54), new NumericType(778)),
        new ShowText(new LiteralString($chapter['title'])),
        new EndTextObject(),
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
        new MoveTextPosition(new NumericType(54), new NumericType(750)),
        new ShowText(new LiteralString($chapter['subtitle'])),
        new EndTextObject(),
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(13)),
        new SetTextLeading(new NumericType(22)),
        new MoveTextPosition(new NumericType(54), new NumericType(692)),
    );

    // with() returns a new sequence: retain that result.
    foreach ($chapter['lines'] as $lineIndex => $line) {
        if ($lineIndex > 0) {
            $contents = $contents->with(new MoveToNextTextLine());
        }
        $contents = $contents->with(new ShowText(new LiteralString($line)));
    }
    $contents = $contents->with(
        new EndTextObject(),
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(10)),
        new MoveTextPosition(new NumericType(54), new NumericType(44)),
        new ShowText(new LiteralString('Page ' . ($index + 1) . ' / ' . count($chapters))),
        new EndTextObject(),
    );

    // MediaBox and Resources are deliberately absent from each Page.
    $page = new PageObject(contents: new StreamObject($contents));
    $pages->addKid($page);
}

// 4. TextString encodes the Unicode metadata title, not text to draw.
$catalog = new DocumentCatalog($pages);
$information = new DocumentInformation(new Title(new TextString('Pages, texte et ressources partagées')));
$file = new FileStructure(
    new FileHeader('1.7'),
    new FileBody($catalog),
    new IndirectReference($catalog),
    new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($information))),
);
$path = $argv[1] ?? __DIR__ . '/pages-and-text.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
