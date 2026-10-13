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
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// Tutoriel 11 : les trois versions du flux restent dans les octets du fichier.
// Le lecteur suit les derniers xref et n'affiche que le contenu de la révision 2.
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference($font)),
)));
$initialContents = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(20)),
    new MoveTextPosition(new NumericType(64), new NumericType(700)),
    new ShowText(new LiteralString('Revision 0 : document initial.')),
    new EndTextObject(),
));
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $initialContents,
);
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
$information = new DocumentInformation(new Title(new TextString('Document initial')));
$initial = (new FileStructure(
    new FileHeader('1.7'),
    new FileBody($catalog),
    trailerEntries: new DictionaryType(
        new Entry(new NameType('Info'), new IndirectReference($information)),
        new Entry(new NameType('ID'), new ArrayType(
            new HexadecimalString('incremental-example-original'),
            new HexadecimalString('incremental-example-revision-0'),
        )),
    ),
    crossReferenceFormat: CrossReferenceFormat::Stream,
))->toRevision();

// L'identifiant appartient à l'instantané, pas à un compteur global.
// Il est conservé : remplacer un objet vivant ne change pas sa génération.
$contentIdentifier = $initial->binding($initialContents)->identifier;
$revision = $initial;
foreach ([1, 2] as $number) {
    $contents = new StreamObject(new ContentStreams(
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(22)),
        new MoveTextPosition(new NumericType(64), new NumericType(700)),
        new ShowText(new LiteralString('Revision ' . $number . ' : contenu mis a jour.')),
        new EndTextObject(),
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
        new MoveTextPosition(new NumericType(64), new NumericType(646)),
        new ShowText(new LiteralString('La police, la page et le catalogue restent les objets initiaux.')),
        new EndTextObject(),
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
        new MoveTextPosition(new NumericType(64), new NumericType(614)),
        new ShowText(new LiteralString('Les anciens octets sont conserves ; Prev relie les trois xref.')),
        new EndTextObject(),
    ));
    // FileBody sélectionne explicitement le flux à remplacer. Une référence
    // historique seule ne republierait aucun objet déjà enregistré.
    $updatedInformation = new DocumentInformation(new Title(new TextString('Révision incrémentale ' . $number)));
    $revision = (new IncrementalUpdate(
        $revision,
        new FileBody(new IndirectObject($contents, $contentIdentifier)),
        trailerEntries: new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($updatedInformation))),
    ))->toRevision();
}

// Le premier ID reste identique ; le writer renouvelle le second à chaque fois.
// Les octets de la révision initiale forment toujours le préfixe exact du PDF.
if (!str_starts_with($revision->bytes, $initial->bytes)) {
    throw new LogicException('The initial revision must remain byte-for-byte intact.');
}
$path = $argv[1] ?? __DIR__ . '/incremental-update.pdf';
$revision->writeTo($path);
echo $path . PHP_EOL;
