<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
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
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. Ces cles sont PUBLIQUES et reservees aux tests. Elles ne prouvent aucune
// identite et ne servent jamais a signer un document de production. Le script
// fournit les octets : aucun chemin n'est interprete par l'API de signature.
$fixtures = dirname(__DIR__) . '/tests/Fixtures/signing/';
$certificateBytes = file_get_contents($fixtures . 'signer.der');
$keyBytes = file_get_contents($fixtures . 'signer-private-key.pem');
if ($certificateBytes === false || $keyBytes === false) {
    throw new RuntimeException('The public signing fixtures are unavailable.');
}
$certificate = new X509Certificate(new HexadecimalString($certificateBytes));
$chain = new CertificateChain($certificate);
$identity = new OpenSslIdentity($chain, $keyBytes);
$backend = new OpenSslSignatureBackend($identity);

// 2. Le dictionnaire reserve la capacite binaire de Contents. Le champ Sig lui
// donne une association documentaire V ; un widget visible est un autre objet.
$pending = new PendingSignature(
    new SignatureCapacity(8192),
    SignatureFormat::Pkcs7Detached,
    entries: new Entry(new NameType('Reason'), new LiteralString('Public educational fixture'))
);
$fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('Demonstration')), value: new IndirectReference($pending)));
$form = new AcroForm($fields, entries: new Entry(new NameType('SigFlags'), new IntegerType(3)));
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))))));
$content = new StreamObject(new ContentStreams(
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(18)),
    new MoveTextPosition(new NumericType(48), new NumericType(740)),
    new ShowText(new LiteralString('Un document signe objet par objet')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(0), new NumericType(-48)),
    new ShowText(new LiteralString('ByteRange couvre le fichier, sauf la reservation Contents.')),
    new MoveTextPosition(new NumericType(0), new NumericType(-24)),
    new ShowText(new LiteralString('Certificat et cle publics de test : aucune confiance attribuee.')),
    new EndTextObject(),
));
$page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)), $resources, $content);
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('AcroForm'), new IndirectReference($form)));
$file = new FileStructure(new FileHeader(), new FileBody($catalog));

// 3. prepare() fixe ByteRange avant le CMS ; sign() remplit uniquement Contents
// dans sa reservation. Les offsets xref et la longueur du document restent fixes.
$prepared = (new SignatureWriter())->prepare($file, $pending);
$signed = $prepared->sign($backend);
$path = $argv[1] ?? __DIR__ . '/signed-document.pdf';
$signed->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
