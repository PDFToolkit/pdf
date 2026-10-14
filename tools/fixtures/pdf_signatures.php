<?php

declare(strict_types=1);

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Serialization\FileWriter;
use PDF\Security as Sec;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create signature fixture output.');
}
$fixtures = dirname(__DIR__, 2) . '/tests/Fixtures/signing/';
$chain = new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents($fixtures . 'signer.der'))));
$backend = new OpenSslSignatureBackend(new OpenSslIdentity($chain, file_get_contents($fixtures . 'signer-private-key.pem')));
$manifest = [];
$id = new Sec\FileIdentifier(new HexadecimalString(str_repeat('s', 16)));
$operations = ['plain' => null];
foreach ([Sec\HistoricalSecurityRevision::Revision2, Sec\HistoricalSecurityRevision::Revision3, Sec\HistoricalSecurityRevision::Revision4] as $revision) {
    foreach ($revision === Sec\HistoricalSecurityRevision::Revision4 ? Sec\HistoricalDataCipher::cases() : [Sec\HistoricalDataCipher::Rc4] as $cipher) {
        $operations[strtolower($revision->name . '-' . $cipher->name)] = (new Sec\HistoricalStandardSecurityHandler(aes: new Sec\OpenSslAes128Backend()))->create(
            $revision,
            new Sec\PdfDocEncodedPassword(new HexadecimalString('user')),
            new Sec\PdfDocEncodedPassword(new HexadecimalString('owner')),
            new Sec\HistoricalPermissions(new IntegerType($revision === Sec\HistoricalSecurityRevision::Revision2 ? -64 : -4), $revision),
            $id,
            new Sec\HistoricalKeyLength(new IntegerType($revision === Sec\HistoricalSecurityRevision::Revision2 ? 40 : 128)),
            $cipher,
        );
    }
}
$profile = new Sec\Saslprep();
$operations['revision5-aes256'] = (new Sec\HistoricalRevision5SecurityHandler(new Sec\OpenSslAes256Backend(), new Sec\SystemRandomSource()))
    ->create($profile->prepare('user'), $profile->prepare('owner'), new Sec\Revision5Permissions(-4), new Sec\EncryptMetadata(true));
foreach ($operations as $securityName => $encryption) {
    foreach (CrossReferenceFormat::cases() as $xref) {
        foreach ([SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Sha1, SignatureFormat::X509RsaSha1] as $format) {
            $cert = $format === SignatureFormat::X509RsaSha1 ? [new Entry(new NameType('Cert'), $chain->object())] : [];
            $pending = new PendingSignature(
                new SignatureCapacity(8192),
                $format,
                new NameType('Adobe.PPKLite'),
                ...[new Entry(new NameType('Reason'), new LiteralString('Independent test')), ...$cert]
            );
            $fields = (new FieldTree())->addRoot(new SignatureField(
                new PartialFieldName(new TextString('approval')),
                value: new IndirectReference($pending)
            ));
            $form = new AcroForm($fields, entries: new Entry(new NameType('SigFlags'), new IntegerType(3)));
            $page = new PageObject(
                new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(100)),
                new ResourceDictionary(),
                new StreamObject("% Signature fixture page\n")
            );
            $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('AcroForm'), new IndirectReference($form)));
            if ($encryption instanceof Sec\HistoricalRevision5Encryption) {
                $catalog = $catalog->with(new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(
                    new Entry(new NameType('BaseVersion'), new NameType('1.7')),
                    new Entry(new NameType('ExtensionLevel'), new IntegerType(3)),
                )))));
            }
            $trailer = $encryption === null ? null : new DictionaryType(new Entry(new NameType('ID'), new \PDF\Syntax\Object\ArrayType($id->value, $id->value)));
            $file = new FileStructure(new FileHeader(), new FileBody($catalog), trailerEntries: $trailer, crossReferenceFormat: $xref);
            $writer = new SignatureWriter(new FileWriter(encryption: $encryption));
            $signed = $writer->prepare($file, $pending)->sign($backend);
            $filename = $securityName . '-' . strtolower($xref->name) . '-' . strtolower($format->name) . '.pdf';
            $signed->writeTo($destination . '/' . $filename);
            $second = new PendingSignature(new SignatureCapacity(8192), $format, new NameType('Adobe.PPKLite'), ...$cert);
            $fields->addRoot(new SignatureField(new PartialFieldName(new TextString('secondApproval')), value: new IndirectReference($second)));
            $update = (new IncrementalUpdate($signed->revision, new FileBody($form)))->toRevision();
            $twice = $writer->prepareRevision($update, $second)->sign($backend);
            $twice->writeTo($destination . '/two-' . $filename);
            $manifest[] = ['file' => $filename, 'secondFile' => 'two-' . $filename, 'format' => $format->value, 'password' => $encryption === null ? null : 'user'];
        }
    }
}
file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($manifest) . " signed and incrementally signed PDFs generated.\n";
