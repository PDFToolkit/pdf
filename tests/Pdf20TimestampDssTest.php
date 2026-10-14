<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Extensions\IsoExtensions;
use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Signatures\DocumentSecurityStore;
use PDF\Document\Signatures\PendingDocumentTimestamp;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\Rfc3161TimestampBackend;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureValidationEvidence;
use PDF\Document\Signatures\SignatureValidationKey;
use PDF\Document\Signatures\SignatureVerificationInput;
use PDF\Document\Signatures\SignedRevision;
use PDF\Document\Signatures\TimestampRequest;
use PDF\Document\Signatures\TimestampSerialSequence;
use PDF\Document\Signatures\ValidationDataKind;
use PDF\Document\Signatures\ValidationDataReferences;
use PDF\Document\Signatures\ValidationDataStream;
use PDF\Document\Signatures\ValidationRelatedInformation;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\EncryptMetadata;
use PDF\Security\LocalTimestampAuthority;
use PDF\Security\ModernSecurityRevision;
use PDF\Security\ModernStandardSecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\OpenSslTimestampTokenVerifier;
use PDF\Security\Pdf20Permissions;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PreparedPassword;
use PDF\Security\SystemRandomSource;
use PDF\Security\X509Certificate;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Document\ResourceDictionary;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Pdf20TimestampDssTest extends TestCase
{
    /** @return iterable<string,array{CrossReferenceFormat,bool,?ModernSecurityRevision}> */
    public static function layouts(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            foreach ([false, true] as $linearized) {
                foreach ([null, ...ModernSecurityRevision::cases()] as $encryption) {
                    yield $format->name . ($linearized ? ' linearized ' : ' ') . ($encryption?->name ?? 'clear') => [$format, $linearized, $encryption];
                }
            }
        }
    }

    #[DataProvider('layouts')]
    public function testNativeTimestampAndSubsequentDssUseExactRevisionObjects(CrossReferenceFormat $format, bool $linearized, ?ModernSecurityRevision $cipher): void
    {
        [$backend, $chain] = $this->backend();
        $pending = new PendingDocumentTimestamp(new SignatureCapacity(8192));
        $field = new SignatureField(new PartialFieldName(new TextString('timestamp')), value: new IndirectReference($pending));
        $form = new AcroForm((new FieldTree())->addRoot($field));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary());
        $entries = [new Entry(new NameType('AcroForm'), new IndirectReference($form))];
        $encryption = null;
        $mac = null;
        if ($cipher !== null) {
            $random = new SystemRandomSource();
            $encryption = (new ModernStandardSecurityHandler(new OpenSslAes256Backend(), $random))->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Pdf20Permissions(-4100, true), new EncryptMetadata(true), $cipher, str_repeat('S', 32));
            $mac = new PdfMacTokenComposer($encryption->key, str_repeat('S', 32), $random);
            $entries[] = new Entry(new NameType('Extensions'), new IsoExtensions(...($cipher === ModernSecurityRevision::Aes256Gcm ? [IsoTechnicalSpecification::IntegrityProtection, IsoTechnicalSpecification::AesGcm] : [IsoTechnicalSpecification::IntegrityProtection])));
        }
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), ...$entries);
        $ids = new ArrayType(new HexadecimalString(str_repeat('i', 16)), new HexadecimalString(str_repeat('i', 16)));
        $file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), $ids)), crossReferenceFormat: $format);
        $writer = $linearized ? new LinearizedWriter(encryption: $encryption, mac: $mac, macSignature: $mac === null ? null : $pending) : new FileWriter(encryption: $encryption, mac: $mac, macSignature: $mac === null ? null : $pending);
        $prepared = new PreparedSignature($writer->revision($file), $pending, mac: $mac);
        $signed = $prepared->sign($backend);
        $backend->verify(new SignatureVerificationInput($signed->range->coveredBytes($signed->revision->bytes), $signed->value, SignatureFormat::Rfc3161, $chain));
        self::assertSame('DocTimeStamp', $signed->revision->binding($pending)->dictionary->get('Type')->value);
        $references = new ValidationDataReferences(ValidationDataKind::Certificate, new IndirectReference(new ValidationDataStream(ValidationDataKind::Certificate, $chain->signer->value->value)));
        $policy = new class ($backend, $chain) implements SignatureValidationEvidence {
            public function __construct(private Rfc3161TimestampBackend $backend, private CertificateChain $chain)
            {
            }
            public function assertSuccessful(SignedRevision $signature): void
            {
                $this->backend->verify(new SignatureVerificationInput($signature->range->coveredBytes($signature->revision->bytes), $signature->value, $signature->format, $this->chain));
            }
        };
        $vri = new ValidationRelatedInformation($signed, $policy, certificates: $references);
        $store = new DocumentSecurityStore(certificates: $references, validation: $vri);
        $catalogRecord = $signed->revision->binding($catalog);
        $updatedCatalog = $catalogRecord->dictionary->with(new Entry(new NameType('DSS'), new IndirectReference($store)));
        $updated = (new IncrementalWriter(mac: $mac))->serialize(new IncrementalUpdate($signed->revision, new FileBody(new IndirectObject($updatedCatalog, $catalogRecord->identifier))));
        self::assertStringStartsWith($signed->revision->bytes, $updated->bytes);
        self::assertSame(strtoupper(sha1($signed->revision->binding($pending)->dictionary->get('Contents')->value)), (new SignatureValidationKey($signed))->name->value);
        self::assertNotSame(strtoupper(sha1($signed->value->bytes)), $vri->key->name->value);
        self::assertSame('DSS', $updated->binding($store)->dictionary->get('Type')->value);
    }

    public function testTimestampNonceAndImprintMustMatchTheActualRequest(): void
    {
        [$backend, $chain, $authority] = $this->backend();
        $request = new TimestampRequest(SignatureDigestMethod::SHA256, hash('sha256', 'document', true), nonce: 'nonce');
        $token = $authority->request($request);
        $this->expectException(\InvalidArgumentException::class);
        $token->validateRequest(new TimestampRequest($request->digest, $request->messageImprint, nonce: 'other'));
    }

    public function testNonTsaCertificateCannotBeSelectedAsATimestampAuthority(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/Fixtures/iso32000-crypto/keys.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->expectException(\InvalidArgumentException::class);
        new OpenSslTimestampTokenVerifier(new CertificateChain(new X509Certificate(new HexadecimalString(base64_decode($fixtures['keys'][0]['certificate'])))));
    }

    /** @return iterable<string,array{string}> */
    public static function forbiddenTimestampEntries(): iterable
    {
        yield 'Reference' => ['Reference'];
        yield 'Changes' => ['Changes'];
    }

    #[DataProvider('forbiddenTimestampEntries')]
    public function testTimestampCannotCarryApprovalSignatureOnlyEntries(string $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PendingDocumentTimestamp(new SignatureCapacity(8192), entries: new Entry(new NameType($entry), new ArrayType()));
    }

    public function testDssRejectsAnArrayWithTheWrongEvidenceKind(): void
    {
        $wrong = new ValidationDataReferences(ValidationDataKind::Certificate, new IndirectReference(new ValidationDataStream(ValidationDataKind::Certificate, "\x30\0")));
        $this->expectException(\InvalidArgumentException::class);
        new DocumentSecurityStore(revocationLists: $wrong);
    }

    public function testVriEvidenceMustOccurByIdentityInTheCorrespondingDssArray(): void
    {
        [$backend, $chain] = $this->backend();
        $pending = new PendingDocumentTimestamp(new SignatureCapacity(8192));
        $field = new SignatureField(new PartialFieldName(new TextString('timestamp')), value: new IndirectReference($pending));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm((new FieldTree())->addRoot($field)))));
        $signed = (new PreparedSignature((new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision(), $pending))->sign($backend);
        $evidence = new class ($backend, $chain) implements SignatureValidationEvidence {
            public function __construct(private Rfc3161TimestampBackend $backend, private CertificateChain $chain)
            {
            }
            public function assertSuccessful(SignedRevision $signature): void
            {
                $this->backend->verify(new SignatureVerificationInput($signature->range->coveredBytes($signature->revision->bytes), $signature->value, $signature->format, $this->chain));
            }
        };
        $certificate = $chain->signer->value->value;
        $selected = new ValidationDataReferences(ValidationDataKind::Certificate, new IndirectReference(new ValidationDataStream(ValidationDataKind::Certificate, $certificate)));
        $differentObject = new ValidationDataReferences(ValidationDataKind::Certificate, new IndirectReference(new ValidationDataStream(ValidationDataKind::Certificate, $certificate)));
        $vri = new ValidationRelatedInformation($signed, $evidence, certificates: $selected);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('also occur');
        new DocumentSecurityStore(certificates: $differentObject, validation: $vri);
    }

    public function testRawVriCannotAppearInAnInitialFile(): void
    {
        $vri = new DictionaryType(new Entry(new NameType(str_repeat('A', 40)), new DictionaryType()));
        $store = new DictionaryType(new Entry(new NameType('VRI'), $vri));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('DSS'), $store));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('incremental');
        (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
    }

    public function testRawDssEvidenceCannotBeAnInlineOrEmptyArray(): void
    {
        $store = new DictionaryType(new Entry(new NameType('Certs'), new ArrayType()));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('DSS'), $store));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nonempty');
        (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
    }

    public function testOrphanCmsDictionaryDoesNotBecomeVriEvidenceForAnActualDocumentSignature(): void
    {
        $pending = new \PDF\Document\Signatures\PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached);
        $catalog = new DocumentCatalog(new PageTreeNode());
        $file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog, $pending));
        $certificate = file_get_contents(__DIR__ . '/Fixtures/signing/signer.der');
        $key = file_get_contents(__DIR__ . '/Fixtures/signing/signer-private-key.pem');
        $identity = new \PDF\Security\OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString($certificate))), $key);
        $signed = (new \PDF\Serialization\SignatureWriter())->prepare($file, $pending)->sign(new \PDF\Security\OpenSslSignatureBackend($identity));
        $contents = $signed->revision->binding($pending)->dictionary->get('Contents')->value;
        $store = new DictionaryType(new Entry(new NameType('VRI'), new DictionaryType(new Entry(new NameType(strtoupper(sha1($contents))), new DictionaryType()))));
        $record = $signed->revision->binding($catalog);
        $changed = $record->dictionary->with(new Entry(new NameType('DSS'), $store));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('earlier revision');
        (new IncrementalUpdate($signed->revision, new FileBody(new IndirectObject($changed, $record->identifier))))->toRevision();
    }

    public function testSignatureDerPaddingDoesNotRemoveAnActualFinalZeroOctet(): void
    {
        $contents = new \PDF\Document\Signatures\SignatureContentsValue(new HexadecimalString("\x04\x01\0\0\0"));
        self::assertSame("\x04\x01\0", $contents->value->bytes);
        $this->expectException(\InvalidArgumentException::class);
        new \PDF\Document\Signatures\SignatureContentsValue(new HexadecimalString("\x04\x01\0\0\x01"));
    }

    /** @return array{Rfc3161TimestampBackend,CertificateChain,LocalTimestampAuthority} */
    private function backend(): array
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/Fixtures/iso32000-crypto/timestamp.json'), true, flags: JSON_THROW_ON_ERROR);
        $chain = new CertificateChain(new X509Certificate(new HexadecimalString(base64_decode($fixtures['certificate']))));
        $openssl = null;
        foreach ([getenv('PDFTOOLKIT_OPENSSL'), '/usr/bin/openssl', 'C:/Program Files/Git/mingw64/bin/openssl.exe'] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                $openssl = $candidate;
                break;
            }
        }
        if ($openssl === null) {
            self::markTestSkipped('The explicitly selected offline TSA requires an OpenSSL executable.');
        }
        $authority = new LocalTimestampAuthority($openssl, $chain, $fixtures['private_key'], new TimestampSerialSequence(), '1.2.3.4.1');
        return [new Rfc3161TimestampBackend($authority, new OpenSslTimestampTokenVerifier($chain)), $chain, $authority];
    }
}
