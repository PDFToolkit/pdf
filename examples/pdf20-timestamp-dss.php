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
use PDF\Document\Signatures\DocumentSecurityStore;
use PDF\Document\Signatures\PendingDocumentTimestamp;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\Rfc3161TimestampBackend;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureValidationEvidence;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\SignedRevision;
use PDF\Document\Signatures\TimestampSerialSequence;
use PDF\Document\Signatures\ValidationDataKind;
use PDF\Document\Signatures\ValidationDataReferences;
use PDF\Document\Signatures\ValidationDataStream;
use PDF\Document\Signatures\ValidationRelatedInformation;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\LocalTimestampAuthority;
use PDF\Security\OpenSslTimestampTokenVerifier;
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
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. The example application explicitly selects an OFFLINE authority provider.
// Its private key and certificate are PUBLIC TEST DATA, not a trusted service.
// Set PDFTOOLKIT_OPENSSL or pass the executable as the second argument on Windows.
$fixtureBytes = file_get_contents(dirname(__DIR__) . '/tests/Fixtures/iso32000-crypto/timestamp.json');
if ($fixtureBytes === false) {
    throw new RuntimeException('The public timestamp fixture is unavailable.');
}
$fixture = json_decode($fixtureBytes, true, flags: JSON_THROW_ON_ERROR);
$certificateBytes = base64_decode($fixture['certificate'], true);
if ($certificateBytes === false) {
    throw new RuntimeException('Invalid public timestamp certificate encoding.');
}
$chain = new CertificateChain(new X509Certificate(new HexadecimalString($certificateBytes)));
$openssl = $argv[2] ?? (getenv('PDFTOOLKIT_OPENSSL') ?: throw new InvalidArgumentException('Supply the OpenSSL executable as argument two or PDFTOOLKIT_OPENSSL.'));
$authority = new LocalTimestampAuthority($openssl, $chain, $fixture['private_key'], new TimestampSerialSequence(), '1.2.3.4.1');
$backend = new Rfc3161TimestampBackend($authority, new OpenSslTimestampTokenVerifier($chain));

// 2. A document timestamp has its own /DocTimeStamp dictionary and /ETSI.RFC3161
// SubFilter. The provider receives only a message imprint and nonce. The returned
// token must authenticate that exact request and the selected authority key.
$pending = new PendingDocumentTimestamp(new SignatureCapacity(8192));
$field = new SignatureField(new PartialFieldName(new TextString('FirstTimestamp')), value: new IndirectReference($pending));
$form = new AcroForm((new FieldTree())->addRoot($field));
$resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference(new Type1Font(StandardFont::Helvetica))))));
$content = new \PDF\Syntax\Object\StreamObject(new ContentStreams(
    new Op\BeginTextObject(),
    new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(20)),
    new Op\MoveTextPosition(new NumericType(48), new NumericType(740)),
    new Op\ShowText(new LiteralString('PDF 2.0: timestamps and validation evidence')),
    new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-48)),
    new Op\ShowText(new LiteralString('1. Timestamp the exact document byte range.')),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-28)),
    new Op\ShowText(new LiteralString('2. Append a DSS and signature-specific VRI evidence.')),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-28)),
    new Op\ShowText(new LiteralString('3. Append a second timestamp over the updated document.')),
    new Op\MoveTextPosition(new NumericType(0), new NumericType(-40)),
    new Op\ShowText(new LiteralString('PUBLIC TEST AUTHORITY: no production trust is asserted.')),
    new Op\EndTextObject(),
));
$page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)), $resources, $content);
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('AcroForm'), new IndirectReference($form)));
$first = (new SignatureWriter())->prepare(new FileStructure(new FileHeader('2.0'), new FileBody($catalog)), $pending)->sign($backend);

// 3. A VRI entry is permitted only after successful validation under an explicit
// caller policy. This educational policy accepts the public fixture authority
// after mathematical verification; production policies also establish trust,
// certificate validity and revocation. Merely embedding a certificate proves none.
$policy = new class ($backend, $chain) implements SignatureValidationEvidence {
    public function __construct(private Rfc3161TimestampBackend $backend, private CertificateChain $chain)
    {
    }
    public function assertSuccessful(SignedRevision $signature): void
    {
        $this->backend->verify(new SignatureVerificationInput($signature->range->coveredBytes($signature->revision->bytes), $signature->value, $signature->format, $this->chain));
    }
};
$references = new ValidationDataReferences(ValidationDataKind::Certificate, new IndirectReference(new ValidationDataStream(ValidationDataKind::Certificate, $certificateBytes)));
$information = new ValidationRelatedInformation($first, $policy, certificates: $references);
$store = new DocumentSecurityStore(certificates: $references, validation: $information);
$catalogRecord = $first->revision->binding($catalog);
$updatedCatalog = ($catalogRecord->dictionary ?? throw new LogicException('Missing catalog.'))->with(new Entry(new NameType('DSS'), new IndirectReference($store)));
$withEvidence = (new IncrementalUpdate($first->revision, new FileBody(new IndirectObject($updatedCatalog, $catalogRecord->identifier))))->toRevision();

// 4. A separate timestamp field is appended in a new revision. The earlier
// document bytes remain identical. This new seal also covers the DSS/VRI update.
$secondPending = new PendingDocumentTimestamp(new SignatureCapacity(8192));
$secondField = new SignatureField(new PartialFieldName(new TextString('SecondTimestamp')), value: new IndirectReference($secondPending));
$formRecord = $withEvidence->binding($form);
$formDictionary = $formRecord->dictionary ?? throw new LogicException('Missing AcroForm.');
$roots = $formDictionary->get('Fields');
if (!$roots instanceof ArrayType) {
    throw new LogicException('An AcroForm has its explicit field roots.');
}
$secondForm = $formDictionary->with(new Entry(new NameType('Fields'), new ArrayType(...[...$roots->items, new IndirectReference($secondField)])));
$updated = (new IncrementalUpdate($withEvidence, new FileBody(new IndirectObject($secondForm, $formRecord->identifier))))->toRevision();
$final = (new PreparedSignature($updated, $secondPending))->sign($backend);
$path = $argv[1] ?? __DIR__ . '/pdf20-timestamp-dss.pdf';
$final->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
