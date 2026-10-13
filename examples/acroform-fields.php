<?php

declare(strict_types=1);

use PDF\Document\Extensions\DeveloperExtension;
use PDF\Document\Extensions\DeveloperExtensionEntry;
use PDF\Document\Extensions\DeveloperExtensions;
use PDF\Document\Extensions\DeveloperPrefix;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\AppearanceState;
use PDF\Document\Forms\AppearanceStates;
use PDF\Document\Forms\ButtonField;
use PDF\Document\Forms\ButtonFieldFlag;
use PDF\Document\Forms\ChoiceField;
use PDF\Document\Forms\ChoiceFieldFlag;
use PDF\Document\Forms\ChoiceOption;
use PDF\Document\Forms\ChoiceOptions;
use PDF\Document\Forms\ChoiceSelection;
use PDF\Document\Forms\ChoiceFieldValue;
use PDF\Document\Forms\ChoiceValueDomain;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\TextFieldFlags;
use PDF\Document\Forms\ChoiceFieldFlags;
use PDF\Document\Forms\ButtonFieldFlags;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\NonTerminalField;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Specification\Dictionary\ResetFormActionTable238;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineWidth;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingGray;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\ContentStreamsResources\Operators\StrokePath;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\CatalogDictionary\AcroForm as AcroFormEntry;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Extensions;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// Chaque texte, police, ressource, champ et apparence est fourni explicitement.
// L'AcroForm n'effectue aucune mise en page ni mesure de police automatique.
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('Helv'), new IndirectReference($font)))));
$default = new DefaultAppearance(new SetTextFontAndSize(new NameType('Helv'), new NumericType(12)), new SetNonstrokingGray(new NumericType(0)));
$tree = new FieldTree($default, $resources);
$person = new NonTerminalField(new PartialFieldName(new TextString('Person')));
$name = new TextField(new PartialFieldName(new TextString('Name')), value: new TextString('Ada Lovelace'), maximumLength: new IntegerType(40));
$countries = new ChoiceField(
    new PartialFieldName(new TextString('Countries')),
    new ChoiceOptions(new ChoiceOption(new TextString('France'), new TextString('FR')), new ChoiceOption(new TextString('Belgique'), new TextString('BE'))),
    new ChoiceFieldFlags(ChoiceFieldFlag::MultiSelect),
    // L'interprétation /V est explicite : valeurs exportées, comme les lecteurs
    // usuels ; la prose ISO p.454 mentionne aussi les noms affichés.
    new ChoiceFieldValue(ChoiceValueDomain::ExportValues, new ArrayType(new TextString('FR'), new TextString('BE'))),
    new ChoiceSelection(new IntegerType(0), new IntegerType(1)),
);
$consent = new ButtonField(new PartialFieldName(new TextString('Consent')), value: new NameType('Yes'));
$delivery = new ButtonField(new PartialFieldName(new TextString('Delivery')), new ButtonFieldFlags(ButtonFieldFlag::Radio), new NameType('Email'));
$reset = new ButtonField(new PartialFieldName(new TextString('Reset')), new ButtonFieldFlags(ButtonFieldFlag::Pushbutton));
$signature = new SignatureField(new PartialFieldName(new TextString('Signature')), lock: new SignatureFieldLock(SignatureLockAction::All, permissions: new IntegerType(2)));
$person->addField($name)->addField($countries)->addField($consent)->addField($delivery)->addField($reset)->addField($signature);
$tree->addRoot($person);

// Une apparence est un XObject Form dont BBox est local au widget. Les octets
// visibles sont distincts de /V : changer la valeur demande une nouvelle AP,
// ou une demande explicite NeedAppearances au lecteur. Ici AP est fournie.
$drawAppearance = static function (string $text, int $width, int $height) use ($resources): FormAppearanceStream {
    return new FormAppearanceStream(new ContentStreams(
        new SetLineWidth(new NumericType(1)),
        new AppendRectangle(new NumericType(0.5), new NumericType(0.5), new NumericType($width - 1), new NumericType($height - 1)),
        new StrokePath(),
        new BeginTextObject(),
        new SetTextFontAndSize(new NameType('Helv'), new NumericType(12)),
        new MoveTextPosition(new NumericType(8), new NumericType(($height - 12) / 2)),
        new ShowText(new LiteralString($text)),
        new EndTextObject(),
    ), new Rectangle(new NumericType(0), new NumericType(0), new NumericType($width), new NumericType($height)), $resources);
};
$rectangle = static fn (int $x, int $y, int $width, int $height): Rectangle => new Rectangle(new NumericType($x), new NumericType($y), new NumericType($x + $width), new NumericType($y + $height));
$print = new Entry(new NameType('F'), new IntegerType(4));
$nameWidget = new WidgetAnnotation($rectangle(64, 620, 320, 32), new WidgetAppearance($drawAppearance('Ada Lovelace', 320, 32)), null, $print);
$countryWidget = new WidgetAnnotation($rectangle(64, 538, 320, 32), new WidgetAppearance($drawAppearance('France + Belgique (FR, BE)', 320, 32)), null, $print);

// Boutons : /V est le nom de l'état sélectionné. Chaque widget conserve son
// propre /AS et ses deux apparences /Off et /Yes (ou Email/Paper pour la radio).
$checkboxAppearances = new AppearanceStates(new AppearanceState(new NameType('Off'), $drawAppearance('', 24, 24)), new AppearanceState(new NameType('Yes'), $drawAppearance('X', 24, 24)));
$consentWidget = new WidgetAnnotation($rectangle(64, 460, 24, 24), new WidgetAppearance($checkboxAppearances), new NameType('Yes'), $print);
$emailAppearances = new AppearanceStates(new AppearanceState(new NameType('Off'), $drawAppearance('', 24, 24)), new AppearanceState(new NameType('Email'), $drawAppearance('X', 24, 24)));
$paperAppearances = new AppearanceStates(new AppearanceState(new NameType('Off'), $drawAppearance('', 24, 24)), new AppearanceState(new NameType('Paper'), $drawAppearance('X', 24, 24)));
$emailWidget = new WidgetAnnotation($rectangle(64, 382, 24, 24), new WidgetAppearance($emailAppearances), new NameType('Email'), $print);
$paperWidget = new WidgetAnnotation($rectangle(210, 382, 24, 24), new WidgetAppearance($paperAppearances), new NameType('Off'), $print);
$resetWidget = new WidgetAnnotation($rectangle(64, 300, 220, 32), new WidgetAppearance($drawAppearance('Reinitialiser les champs', 220, 32)), null, $print, new Entry(new NameType('A'), new ResetFormActionTable238(new Entry(new NameType('S'), new NameType('ResetForm')))));
$signatureWidget = new WidgetAnnotation($rectangle(64, 218, 320, 32), new WidgetAppearance($drawAppearance('Signature non renseignee', 320, 32)), null, $print);
$name->addWidget($nameWidget);
$countries->addWidget($countryWidget);
$consent->addWidget($consentWidget);
$delivery->addWidget($emailWidget)->addWidget($paperWidget);
$reset->addWidget($resetWidget);
$signature->addWidget($signatureWidget);
$widgets = [$nameWidget, $countryWidget, $consentWidget, $emailWidget, $paperWidget, $resetWidget, $signatureWidget];

$contents = new ContentStreams(new BeginTextObject(), new SetTextFontAndSize(new NameType('Helv'), new NumericType(24)), new MoveTextPosition(new NumericType(64), new NumericType(728)), new ShowText(new LiteralString('PDFToolkit - formulaires objets')), new EndTextObject());
foreach ([['Nom : Person.Name', 674], ['Pays : choix multiples et valeurs exportees', 592], ['Consentement : case a cocher', 514], ['Livraison : groupe de boutons radio', 436], ['Bouton sans valeur permanente', 354], ['Champ de signature et verrou Adobe', 272], ['Ce fichier contient des apparences explicites.', 166], ['La signature reste vide : aucun CMS n\'est genere.', 142]] as [$label, $y]) {
    $contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('Helv'), new NumericType(12)), new MoveTextPosition(new NumericType(64), new NumericType($y)), new ShowText(new LiteralString($label)), new EndTextObject());
}
$contents = $contents->with(new BeginTextObject(), new SetTextFontAndSize(new NameType('Helv'), new NumericType(12)), new MoveTextPosition(new NumericType(100), new NumericType(388)), new ShowText(new LiteralString('Email')), new MoveTextPosition(new NumericType(146), new NumericType(0)), new ShowText(new LiteralString('Papier')), new EndTextObject());

// /Parent lie le widget au champ ; /P pointe sa vraie page, qui le référence
// dans /Annots. Ces relations sont contrôlées avant l'écriture.
$page = new PageObject($rectangle(0, 0, 612, 792), $resources, new StreamObject($contents), new Entry(new NameType('Annots'), new ArrayType(...array_map(static fn (WidgetAnnotation $widget) => new IndirectReference($widget), $widgets))));
foreach ($widgets as $widget) {
    $widget->bindToPage($page);
}
$pages = (new PageTreeNode())->addKid($page);
$form = new AcroForm($tree);
$extensions = new DeveloperExtensions(new DeveloperExtensionEntry(new DeveloperPrefix(new NameType('ADBE')), new DeveloperExtension(new NameType('1.7'), new IntegerType(3))));
$catalog = new DocumentCatalog($pages, new AcroFormEntry(new IndirectReference($form)), new Extensions($extensions));
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/acroform-fields.pdf';
$file->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
