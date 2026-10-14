<?php

declare(strict_types=1);

use PDF\Document\Extensions\IsoExtensions;
use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\ModernCmsSignatureBackend;
use PDF\Security\OpenSslIdentity;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
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
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. These synthetic credentials are PUBLIC TEST DATA. They demonstrate the
// mathematical operation and assert no trusted identity. Applications supply
// their own certificate and key bytes through the same explicit boundary.
$fixtureBytes = file_get_contents(dirname(__DIR__) . '/tests/Fixtures/iso32000-crypto/keys.json');
if ($fixtureBytes === false) {
    throw new RuntimeException('The public signature fixtures are unavailable.');
}
$fixtures = json_decode($fixtureBytes, true, flags: JSON_THROW_ON_ERROR);
$credential = null;
foreach ($fixtures['keys'] as $candidate) {
    if ($candidate['name'] === 'P-256') {
        $credential = $candidate;
    }
}
if ($credential === null) {
    throw new RuntimeException('The public P-256 demonstration credential is unavailable.');
}
$certificateBytes = base64_decode($credential['certificate'], true);
if ($certificateBytes === false) {
    throw new RuntimeException('The public demonstration certificate has invalid base64 data.');
}
$certificate = new X509Certificate(new HexadecimalString($certificateBytes));
$identity = new OpenSslIdentity(new CertificateChain($certificate), $credential['private_key']);
// The backend inspects the actual certificate's named curve. P-256/SHA3-256
// is an allowed pair; P-256/SHA512 would be rejected before any bytes are signed.
$backend = new ModernCmsSignatureBackend($identity, SignatureDigestMethod::SHA3_256);

// 2. Hash support and signature support are separate ISO extension levels.
// Both are declared in the ISO_ array; choosing the larger number cannot
// replace the smaller declaration. The document header explicitly selects 2.0.
$extensions = new IsoExtensions(IsoTechnicalSpecification::HashAlgorithms, IsoTechnicalSpecification::DigitalSignatures);
$pending = new PendingSignature(new SignatureCapacity(4096), SignatureFormat::Pkcs7Detached, entries: new Entry(new NameType('Reason'), new LiteralString('Public educational fixture')));
$fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('Demonstration')), value: new IndirectReference($pending)));
$form = new AcroForm($fields);
// PDF 2.0 requires explicit metrics and a descriptor even for standard fonts.
$font = require __DIR__ . '/fixtures/pdf20-courier-font.php';
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
$content = new StreamObject(new ContentStreams(
    new Op\BeginTextObject(),
    new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(20)),
    new Op\MoveTextPosition(new NumericType(48), new NumericType(740)),
    new Op\ShowText(new LiteralString('PDF 2.0: an explicit modern signature')),
    new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-48)),
    new Op\ShowText(new LiteralString('Named curve: P-256. Message digest: SHA3-256.')),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-28)),
    new Op\ShowText(new LiteralString('ISO/TS 32001 and ISO/TS 32002 are declared separately.')),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-28)),
    new Op\ShowText(new LiteralString('The exact ByteRange is signed through CMS signed attributes.')),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-40)),
    new Op\ShowText(new LiteralString('PUBLIC TEST KEY: no trusted production identity is asserted.')),
    new Op\EndTextObject(),
));
$page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)), $resources, $content);
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Extensions'), $extensions), new Entry(new NameType('AcroForm'), new IndirectReference($form)));
$file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog));

// 3. Preparing fixes the final byte ranges. Signing hashes those exact bytes,
// hashes DER signedAttrs with the same digest, verifies the mathematical
// result, and fills only the preallocated Contents region.
$prepared = (new SignatureWriter())->prepare($file, $pending);
$signed = $prepared->sign($backend);
$path = $argv[1] ?? __DIR__ . '/pdf20-modern-signature.pdf';
$signed->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
