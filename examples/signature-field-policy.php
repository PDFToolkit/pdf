<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Signatures\SignatureAppearanceSelection;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\Document\Signatures\Policies\SignatureSeedFlag;
use PDF\Document\Signatures\Policies\SignatureSeedFlags;
use PDF\Document\Signatures\Policies\SignatureSeedValue;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\SetLineWidth;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingGray;
use PDF\Syntax\ContentStreamsResources\Operators\SetStrokingGray;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\ContentStreamsResources\Operators\StrokePath;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
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
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. L'application fournit les octets. Ce certificat et cette clé sont PUBLICS
// et réservés aux tests ; ils ne prouvent aucune identité de production.
$fixtures = dirname(__DIR__) . '/tests/Fixtures/signing/';
$certificateBytes = file_get_contents($fixtures . 'signer.der');
$keyBytes = file_get_contents($fixtures . 'signer-private-key.pem');
if ($certificateBytes === false || $keyBytes === false) {
    throw new RuntimeException('The public signing fixtures are unavailable.');
}
$identity = new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString($certificateBytes))), $keyBytes);
$backend = new OpenSslSignatureBackend($identity);

// 2. Les ressources, coordonnées et apparences restent explicites. Le texte
// des streams utilise ici des octets ASCII ; les métadonnées Reason/SV sont
// de véritables TextString et conservent leurs accents.
$resources = new ResourceDictionary(new Font(new DictionaryType(
    new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))),
    new Entry(new NameType('Title'), new IndirectReference(new Type1Font(StandardFont::HelveticaBold))),
)));
$rectangle = static fn (int $x, int $y, int $width, int $height): Rectangle => new Rectangle(new NumericType($x), new NumericType($y), new NumericType($x + $width), new NumericType($y + $height));
$text = static fn (string $value, int $x, int $y, int $size, string $font = 'F1'): ContentStreams => new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType($font), new NumericType($size)),
    new MoveTextPosition(new NumericType($x), new NumericType($y)),
    new ShowText(new LiteralString($value)),
    new EndTextObject(),
);
$referenceAppearance = new FormAppearanceStream(new ContentStreams(
    new SetStrokingGray(new NumericType(0.7)),
    new SetLineWidth(new NumericType(1)),
    new AppendRectangle(new NumericType(0.5), new NumericType(0.5), new NumericType(498), new NumericType(31)),
    new StrokePath(),
    ...$text('DOSSIER-2026-010', 12, 10, 12)->operators,
), $rectangle(0, 0, 499, 32), $resources);
$signatureAppearance = new FormAppearanceStream(new ContentStreams(
    new SetNonstrokingGray(new NumericType(0.95)),
    new AppendRectangle(new NumericType(0), new NumericType(0), new NumericType(499), new NumericType(80)),
    new FillPathNonzero(),
    new SetNonstrokingGray(new NumericType(0)),
    ...$text('Signature mathematiquement verifiee', 16, 54, 15, 'Title')->operators,
    ...$text('CMS detache / SHA-256 / certificat public de test', 16, 31, 11)->operators,
    ...$text('Apparence fournie explicitement par ce script', 16, 13, 9)->operators,
), $rectangle(0, 0, 499, 80), $resources);

// 3. Le seed définit des obligations. Les bits Ff imposent Filter, le premier
// SubFilter pris en charge, DigestMethod et la raison réellement écrite.
// Référence : ISO Table234, PDF32000_2008.pdf, pages455–457.
$reason = new TextString('Étudié et approuvé');
$seed = new SignatureSeedValue(
    new SignatureSeedFlags(SignatureSeedFlag::Filter, SignatureSeedFlag::SubFilter, SignatureSeedFlag::DigestMethod, SignatureSeedFlag::Reasons),
    new Entry(new NameType('Filter'), new NameType('Adobe.PPKLite')),
    new Entry(new NameType('SubFilter'), new ArrayType(new NameType('adbe.pkcs7.detached'))),
    new Entry(new NameType('DigestMethod'), new ArrayType(new NameType('SHA256'))),
    new Entry(new NameType('Reasons'), new ArrayType($reason)),
);
$referenceName = new PartialFieldName(new TextString('Reference'));
$reference = new TextField($referenceName, value: new TextString('DOSSIER-2026-010'));
$lock = new SignatureFieldLock(SignatureLockAction::Include, new ArrayType($referenceName->value));
$signing = new SignatureFieldSigning(
    $backend,
    new PartialFieldName(new TextString('Approval')),
    new SignatureCapacity(8192),
    new SignatureSigningIntent(reason: $reason, appearance: new SignatureAppearanceSelection(new TextString('Approbation pédagogique'), new IndirectReference($signatureAppearance))),
    $seed,
    $lock,
);

// 4. /V du champ Approval désigne la réservation. Son /Lock sélectionne
// Reference. SignatureFieldSigning copie Action/Fields dans FieldMDP, sans
// fabriquer de coordonnées ni modifier le champ cible pour l'application.
$defaultAppearance = new DefaultAppearance(new SetTextFontAndSize(new NameType('F1'), new NumericType(12)), new SetNonstrokingGray(new NumericType(0)));
$fields = (new FieldTree($defaultAppearance, $resources))->addRoot($reference)->addRoot($signing->field);
$referenceWidget = new WidgetAnnotation($rectangle(48, 610, 499, 32), new WidgetAppearance($referenceAppearance), entries: new Entry(new NameType('F'), new IntegerType(4)));
$signatureWidget = new WidgetAnnotation($rectangle(48, 353, 499, 80), new WidgetAppearance($signatureAppearance), entries: new Entry(new NameType('F'), new IntegerType(4)));
$reference->addWidget($referenceWidget);
$signing->field->addWidget($signatureWidget);

$content = new ContentStreams(...[
    ...$text('Signer un champ sous conditions', 48, 760, 22, 'Title')->operators,
    ...$text('Des objets explicites, un contrat applique aux octets ecrits.', 48, 721, 11)->operators,
    ...$text('1  Le formulaire conserve la reference du dossier', 48, 672, 14, 'Title')->operators,
    ...$text('Ce champ est selectionne par le verrou FieldMDP de Approval.', 48, 589, 10)->operators,
    ...$text('2  Le seed impose le format, la raison et le digest', 48, 553, 14, 'Title')->operators,
    ...$text('Filter : Adobe.PPKLite | SubFilter : adbe.pkcs7.detached', 48, 526, 11)->operators,
    ...$text('DigestMethod : SHA256 | Reason : Etudie et approuve', 48, 504, 11)->operators,
    ...$text('3  Le resultat verifie remplit la reservation Contents', 48, 461, 14, 'Title')->operators,
    ...$text('Le CMS signe la revision entiere, sauf la valeur Contents.', 48, 310, 11)->operators,
    ...$text('Le champ, son widget et le verrou appartiennent au meme catalogue.', 48, 289, 10)->operators,
    new SetNonstrokingGray(new NumericType(0.97)), new AppendRectangle(new NumericType(48), new NumericType(128), new NumericType(499), new NumericType(128)), new FillPathNonzero(),
    new SetNonstrokingGray(new NumericType(0)),
    ...$text('Ce que cette signature confirme', 60, 234, 12, 'Title')->operators,
    ...$text('Les octets couverts correspondent au certificat et au CMS generes.', 60, 210, 10)->operators,
    ...$text('Ce certificat et sa cle sont publics et reserves a la demonstration.', 60, 193, 10)->operators,
    ...$text('Aucune identite PKI, aucun horodatage TSA ni preuve OCSP/CRL.', 60, 176, 10)->operators,
    ...$text('Le verrou est une declaration PDF ; la comparaison generale des', 60, 159, 10)->operators,
    ...$text('revisions DocMDP/FieldMDP/UR reste un mecanisme distinct.', 60, 142, 10)->operators,
    new SetNonstrokingGray(new NumericType(0.5)),
    ...$text('PDFToolkit / Tutoriel 26 / PHP 8.3+', 48, 60, 9)->operators,
]);
$page = new PageObject($rectangle(0, 0, 595, 842), $resources, new StreamObject($content), new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($referenceWidget), new IndirectReference($signatureWidget))));
$referenceWidget->bindToPage($page);
$signatureWidget->bindToPage($page);
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm($fields, entries: new Entry(new NameType('SigFlags'), new IntegerType(3))))));

// 5. Data ferme explicitement la liaison circulaire vers ce catalogue. prepare()
// écrit le fichier et fixe ByteRange. Le contrat relit les snapshots réellement
// émis, puis vérifie la signature mathématique, le digest et le certificat du
// résultat avant de remplir Contents. Il n'accorde aucune confiance PKI.
$signing->bindCatalog(new IndirectReference($catalog));
$prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader('1.7'), new FileBody($catalog)), $signing->signature);
$signed = $signing->sign($prepared);
$path = $argv[1] ?? __DIR__ . '/signature-field-policy.pdf';
$signed->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
