<?php

declare(strict_types=1);

use PDF\Document\Extensions\IsoExtensions;
use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures as S;
use PDF\FileStructure;
use PDF\Security as Security;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
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
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$output = $argv[1] ?? throw new InvalidArgumentException('Supply the fixture directory.');
$openssl = $argv[2] ?? throw new InvalidArgumentException('Supply an explicit OpenSSL executable.');
if (!is_dir($output) && !mkdir($output, 0700, true)) {
    throw new RuntimeException('Cannot create timestamp fixture directory.');
}
$fixture = json_decode(file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/iso32000-crypto/timestamp.json'), true, flags: JSON_THROW_ON_ERROR);
$chain = new Security\CertificateChain(new Security\X509Certificate(new HexadecimalString(base64_decode($fixture['certificate']))));
$authority = new Security\LocalTimestampAuthority($openssl, $chain, $fixture['private_key'], new S\TimestampSerialSequence(), '1.2.3.4.1');
$backend = new S\Rfc3161TimestampBackend($authority, new Security\OpenSslTimestampTokenVerifier($chain));
$policy = new class ($backend, $chain) implements S\SignatureValidationEvidence {
    public function __construct(private S\Rfc3161TimestampBackend $backend, private Security\CertificateChain $chain)
    {
    }
    public function assertSuccessful(S\SignedRevision $signature): void
    {
        $this->backend->verify(new S\SignatureVerificationInput($signature->range->coveredBytes($signature->revision->bytes), $signature->value, $signature->format, $this->chain));
    }
};
$approvalCertificate = file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/signing/signer.der');
$approvalBackend = new Security\OpenSslSignatureBackend(new Security\OpenSslIdentity(new Security\CertificateChain(new Security\X509Certificate(new HexadecimalString($approvalCertificate))), file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/signing/signer-private-key.pem')));
$manifest = ['authority' => $fixture['certificate'], 'approvalCertificate' => base64_encode($approvalCertificate), 'cases' => []];
foreach ([false, true] as $certified) {
    foreach (CrossReferenceFormat::cases() as $format) {
        foreach ([false, true] as $linearized) {
            foreach ([null, ...Security\ModernSecurityRevision::cases()] as $cipher) {
                $pending = new S\PendingDocumentTimestamp(new S\SignatureCapacity(8192));
                $field = new SignatureField(new PartialFieldName(new TextString('timestamp1')), value: new IndirectReference($pending));
                $approval = $certified ? new S\SignatureFieldSigning($approvalBackend, new PartialFieldName(new TextString('author')), new S\SignatureCapacity(8192), new S\SignatureSigningIntent(certification: S\Policies\DocMdpPermissions::NoChanges)) : null;
                $initialPending = $approval?->signature ?? $pending;
                $form = new AcroForm((new FieldTree())->addRoot($approval?->field ?? $field));
                $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary());
                $entries = [new Entry(new NameType('AcroForm'), new IndirectReference($form))];
                $encryption = null;
                $mac = null;
                $extensions = new DictionaryType();
                if ($cipher !== null) {
                    $random = new Security\SystemRandomSource();
                    $encryption = (new Security\ModernStandardSecurityHandler(new Security\OpenSslAes256Backend(), $random))->create(new Security\PreparedPassword('user'), new Security\PreparedPassword('owner'), new Security\Pdf20Permissions(-4100, true), new Security\EncryptMetadata(true), $cipher, str_repeat('S', 32));
                    $mac = new Security\PdfMacTokenComposer($encryption->key, str_repeat('S', 32), $random);
                    $extensions = new DictionaryType(...iterator_to_array((new IsoExtensions(...($cipher === Security\ModernSecurityRevision::Aes256Gcm ? [IsoTechnicalSpecification::IntegrityProtection, IsoTechnicalSpecification::AesGcm] : [IsoTechnicalSpecification::IntegrityProtection])))->entries(), false));
                }
                if ($approval !== null) {
                    $entries[] = new Entry(new NameType('Perms'), $approval->certificationPermissions());
                    $extensions = $extensions->with(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3)))));
                }
                if (iterator_count($extensions->entries()) > 0) {
                    $entries[] = new Entry(new NameType('Extensions'), $extensions);
                }
                $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), ...$entries);
                $approval?->bindCatalog(new IndirectReference($catalog));
                $ids = new ArrayType(new HexadecimalString(str_repeat('i', 16)), new HexadecimalString(str_repeat('i', 16)));
                $file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), $ids)), crossReferenceFormat: $format);
                $writer = $linearized ? new LinearizedWriter(encryption: $encryption, mac: $mac, macSignature: $mac === null ? null : $initialPending) : new FileWriter(encryption: $encryption, mac: $mac, macSignature: $mac === null ? null : $initialPending);
                $initial = new S\PreparedSignature($writer->revision($file), $initialPending, mac: $mac);
                $approvalRevision = null;
                if ($approval !== null) {
                    $approvalRevision = $approval->sign($initial)->revision;
                    $formRecord = $approvalRevision->binding($form);
                    $formWithTimestamp = $formRecord->dictionary->with(new Entry(new NameType('Fields'), new ArrayType(...[...$formRecord->dictionary->get('Fields')->items, new IndirectReference($field)])));
                    $first = (new S\PreparedSignature((new IncrementalWriter(mac: $mac, macSignature: $mac === null ? null : $pending))->serialize(new IncrementalUpdate($approvalRevision, new FileBody(new IndirectObject($formWithTimestamp, $formRecord->identifier)))), $pending, mac: $mac))->sign($backend);
                } else {
                    $first = $initial->sign($backend);
                }
                $references = new S\ValidationDataReferences(S\ValidationDataKind::Certificate, new IndirectReference(new S\ValidationDataStream(S\ValidationDataKind::Certificate, $chain->signer->value->value)));
                $vri = new S\ValidationRelatedInformation($first, $policy, certificates: $references);
                $store = new S\DocumentSecurityStore(certificates: $references, validation: $vri);
                $root = $first->revision->binding($catalog);
                $catalogWithEvidence = $root->dictionary->with(new Entry(new NameType('DSS'), new IndirectReference($store)));
                $validation = (new IncrementalWriter(mac: $mac))->serialize(new IncrementalUpdate($first->revision, new FileBody(new IndirectObject($catalogWithEvidence, $root->identifier))));
                $secondPending = new S\PendingDocumentTimestamp(new S\SignatureCapacity(8192));
                $secondField = new SignatureField(new PartialFieldName(new TextString('timestamp2')), value: new IndirectReference($secondPending));
                $record = $validation->binding($form);
                $roots = $record->dictionary->get('Fields');
                $updatedForm = $record->dictionary->with(new Entry(new NameType('Fields'), new ArrayType(...[...$roots->items, new IndirectReference($secondField)])));
                $preparedSecond = (new IncrementalWriter(mac: $mac, macSignature: $mac === null ? null : $secondPending))->serialize(new IncrementalUpdate($validation, new FileBody(new IndirectObject($updatedForm, $record->identifier))));
                $second = (new S\PreparedSignature($preparedSecond, $secondPending, mac: $mac))->sign($backend);
                $prefix = ($certified ? 'p1-' : '') . strtolower($format->name) . ($linearized ? '-linearized' : '') . '-' . ($cipher?->name ?? 'clear');
                if ($approvalRevision !== null) {
                    file_put_contents($output . '/' . $prefix . '-approval.pdf', $approvalRevision->bytes);
                }
                foreach ([['first', $first->revision], ['validation', $validation], ['second', $second->revision]] as [$stage, $revision]) {
                    $name = $prefix . '-' . $stage . '.pdf';
                    file_put_contents($output . '/' . $name, $revision->bytes);
                    $manifest['cases'][] = ['file' => $name, 'stage' => $stage, 'cipher' => $cipher?->value, 'linearized' => $linearized, 'baseLength' => strlen(($approvalRevision ?? $first->revision)->bytes), 'first' => $prefix . '-first.pdf', 'validation' => $prefix . '-validation.pdf', 'approval' => $certified ? $prefix . '-approval.pdf' : null];
                }
            }
        }
    }
}
file_put_contents($output . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo count($manifest['cases']) . " timestamp/DSS/repeated-timestamp PDFs generated.\n";
