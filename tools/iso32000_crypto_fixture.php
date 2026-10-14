<?php

declare(strict_types=1);

use PDF\Document\Extensions\IsoExtensions;
use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureEllipticCurve;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\Policies\SignatureDigestMethod;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileEncryptionKey;
use PDF\Security\ModernCmsSignatureBackend;
use PDF\Security\ModernSecurityRevision;
use PDF\Security\ModernStandardSecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\OpenSslEdDsaSignaturePrimitive;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslModernSignaturePrimitive;
use PDF\Security\Pdf20Permissions;
use PDF\Security\PdfMacSignatureAttachment;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PreparedPassword;
use PDF\Security\SystemRandomSource;
use PDF\Security\X509Certificate;
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
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';
$output = $argv[1] ?? throw new InvalidArgumentException('Supply an output directory.');
$openssl = $argv[2] ?? throw new InvalidArgumentException('Supply the explicit OpenSSL executable.');
if (!is_dir($output) && !mkdir($output, 0700, true)) {
    throw new RuntimeException('Cannot create the fixture directory.');
}
$credentials = json_decode(file_get_contents(dirname(__DIR__) . '/tests/Fixtures/iso32000-crypto/keys.json'), true, flags: JSON_THROW_ON_ERROR)['keys'];
$identities = [];
foreach ($credentials as $credential) {
    $identities[$credential['name']] = new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(base64_decode($credential['certificate'], true)))), $credential['private_key']);
}
$random = new SystemRandomSource();
$manifest = ['notice' => 'Synthetic public test credentials and example file keys only.', 'pdfs' => [], 'signatures' => [], 'tokens' => []];
foreach (ModernSecurityRevision::cases() as $revision) {
    foreach (CrossReferenceFormat::cases() as $xref) {
        foreach ([false, true] as $signed) {
            foreach ([false, true] as $linearized) {
                foreach ([false, true] as $publicKey) {
                    $salt = str_repeat('S', 32);
                    $encryption = (new ModernStandardSecurityHandler(new OpenSslAes256Backend(), $random))->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Pdf20Permissions(-4100, true), new EncryptMetadata(true), $revision, $salt);
                    $key = $encryption->key;
                    if ($publicKey) {
                        $permissions = new \PDF\Security\PublicKeyPermissions(new \PDF\Syntax\Object\IntegerType(-4100));
                        $handler = new \PDF\Security\PublicKeySecurityHandler(new \PDF\Security\OpenSslCmsEnvelopeBackend(), \PDF\Security\PublicKeyPermissionByteOrder::MostSignificantFirst, $random, aes256: new OpenSslAes256Backend());
                        $name = new NameType('DefaultCryptFilter');
                        $filter = $handler->createFilter($name, $revision === ModernSecurityRevision::Aes256Gcm ? \PDF\Security\PublicKeyCipher::Aes256Gcm : \PDF\Security\PublicKeyCipher::Aes256, new \PDF\Security\PublicKeyKeyLength(new \PDF\Syntax\Object\IntegerType(256)), new EncryptMetadata(true), \PDF\Security\PublicKeyRecipientScope::Document, new NameType('DocOpen'), new \PDF\Security\PublicKeyRecipientGroup($permissions, $identities['RSA']->chain->signer));
                        $encryption = new \PDF\Security\PublicKeyEncryption(new \PDF\Security\PublicKeyFilterConfiguration($name, $name, $name, $filter), $permissions, $salt);
                        $key = $filter->material->key;
                    }
                    $mac = new PdfMacTokenComposer($key, $salt, $random);
                    $extensions = [IsoTechnicalSpecification::IntegrityProtection, IsoTechnicalSpecification::HashAlgorithms];
                    if ($revision === ModernSecurityRevision::Aes256Gcm) {
                        $extensions[] = IsoTechnicalSpecification::AesGcm;
                    }
                    $pending = $signed ? new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, new NameType('Adobe.PPKLite')) : null;
                    $entries = [new Entry(new NameType('Extensions'), new IsoExtensions(...$extensions))];
                    if ($pending !== null) {
                        $fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($pending)));
                        $entries[] = new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm($fields)));
                    }
                    $pages = new PageTreeNode();
                    $pages->addKid(new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(200)), new \PDF\Document\ResourceDictionary()));
                    $catalog = new DocumentCatalog($pages, ...$entries);
                    $trailer = new DictionaryType(new Entry(new NameType('ID'), new ArrayType(new HexadecimalString(str_repeat('i', 16)), new HexadecimalString(str_repeat('i', 16)))), new Entry(new NameType('Info'), new IndirectReference(new DictionaryType(new Entry(new NameType('Title'), new LiteralString('PDFToolkit integrity fixture'))))));
                    $file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog), trailerEntries: $trailer, crossReferenceFormat: $xref);
                    $writer = $linearized ? new LinearizedWriter(encryption: $encryption, mac: $mac, macSignature: $pending) : new FileWriter(encryption: $encryption, mac: $mac, macSignature: $pending);
                    $generated = $writer->revision($file);
                    if ($pending !== null) {
                        $generated = (new PreparedSignature($generated, $pending, mac: $mac))->sign(new ModernCmsSignatureBackend($identities['RSA'], SignatureDigestMethod::SHA3_256))->revision;
                    }
                    $name = ($publicKey ? 'public-' : '') . 'r' . $revision->value . '-' . strtolower($xref->name) . '-' . ($signed ? 'signed' : 'standalone') . ($linearized ? '-linearized' : '') . '.pdf';
                    file_put_contents($output . '/' . $name, $generated->bytes);
                    $manifest['pdfs'][] = ['file' => $name, 'revision' => $revision->value, 'key' => base64_encode($key->bytes()), 'salt' => base64_encode($salt), 'signed' => $signed, 'incremental' => false, 'linearized' => $linearized, 'baseLength' => strlen($generated->bytes), 'baseSigned' => $signed, 'xref' => $xref->name, 'publicKey' => $publicKey];
                    $nextMac = new PdfMacTokenComposer($key, $salt, $random);
                    $updated = (new IncrementalWriter(mac: $nextMac))->serialize(new IncrementalUpdate($generated, new FileBody()));
                    $nextName = str_replace('.pdf', '-incremental.pdf', $name);
                    file_put_contents($output . '/' . $nextName, $updated->bytes);
                    $manifest['pdfs'][] = ['file' => $nextName, 'revision' => $revision->value, 'key' => base64_encode($key->bytes()), 'salt' => base64_encode($salt), 'signed' => false, 'incremental' => true, 'linearized' => $linearized, 'baseLength' => strlen($generated->bytes), 'baseSigned' => $signed, 'xref' => $xref->name, 'publicKey' => $publicKey];
                }
            }
        }
    }
}
foreach ($credentials as $credential) {
    if ($credential['name'] === 'RSA') {
        $manifest['recipient'] = $credential;
    }
}
foreach ($credentials as $credential) {
    $curve = SignatureEllipticCurve::tryFrom($credential['name']);
    $digests = $curve?->digests() ?? [SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512];
    foreach ($digests as $digest) {
        $primitive = str_starts_with($credential['name'], 'Ed') ? new OpenSslEdDsaSignaturePrimitive($openssl, $credential['private_key']) : new OpenSslModernSignaturePrimitive();
        $backend = new ModernCmsSignatureBackend($identities[$credential['name']], $digest, $primitive);
        $bytes = 'Exact synthetic PDF byte range: ' . $credential['name'] . ' ' . $digest->value;
        $value = $backend->sign($bytes, SignatureFormat::Pkcs7Detached);
        $mac = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), $random);
        $attached = (new PdfMacSignatureAttachment())->attach($value, $bytes, $mac);
        $manifest['signatures'][] = ['curve' => $credential['name'], 'digest' => $digest->hashName(), 'bytes' => base64_encode($bytes), 'cms' => base64_encode($attached->bytes), 'certificate' => $credential['certificate']];
    }
}
foreach ([SignatureDigestMethod::SHA256, SignatureDigestMethod::SHA384, SignatureDigestMethod::SHA512, SignatureDigestMethod::SHA3_256, SignatureDigestMethod::SHA3_384, SignatureDigestMethod::SHA3_512] as $digest) {
    $mac = new PdfMacTokenComposer(new FileEncryptionKey(str_repeat('K', 32)), str_repeat('S', 32), $random, $digest);
    $manifest['tokens'][] = ['digest' => $digest->hashName(), 'cms' => base64_encode($mac->token('document')), 'signedCms' => base64_encode($mac->token('document', 'signature'))];
}
file_put_contents($output . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo count($manifest['pdfs']) . ' PDFs, ' . count($manifest['signatures']) . ' curve/digest signatures and six MAC digests generated.' . PHP_EOL;
