<?php

declare(strict_types=1);

use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureField;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\SignatureAppearanceSelection;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\Document\Signatures\Policies\CertificateSeedFlag;
use PDF\Document\Signatures\Policies\CertificateSeedFlags;
use PDF\Document\Signatures\Policies\CertificateSubjectName;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\SignatureCertificateSeedValue;
use PDF\Document\Signatures\Policies\SignatureDocumentLock;
use PDF\Document\Signatures\Policies\SignatureSeedFlag;
use PDF\Document\Signatures\Policies\SignatureSeedFlags;
use PDF\Document\Signatures\Policies\SignatureSeedMdp;
use PDF\Document\Signatures\Policies\SignatureSeedValue;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\DataStructure\AsciiString;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create signature policy fixture output.');
}
$fixtures = dirname(__DIR__, 2) . '/tests/Fixtures/signing/';
$identity = new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents($fixtures . 'signer.der')))), file_get_contents($fixtures . 'signer-private-key.pem'));
$backend = new OpenSslSignatureBackend($identity);
$entry = static fn (string $key, BasicType $value): Entry => new Entry(new NameType($key), $value);
$box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(240), new NumericType(100));
$pages = static fn (): PageTreeNode => (new PageTreeNode())->addKid(new PageObject($box, new ResourceDictionary()));
$facts = $backend->certificateFacts();
// ISO Table235, pages458–459 : les contraintes visent le certificat réellement utilisé.
$certificate = new SignatureCertificateSeedValue(new CertificateSeedFlags(CertificateSeedFlag::Subject, CertificateSeedFlag::Issuer, CertificateSeedFlag::SubjectDN, CertificateSeedFlag::KeyUsage),
    $entry('Subject', new ArrayType($facts->certificate())), $entry('Issuer', new ArrayType($facts->certificate())),
    $entry('SubjectDN', new ArrayType(new CertificateSubjectName($entry('CN', new TextString('PDFToolkit signer public test fixture'))))),
    $entry('KeyUsage', new ArrayType(new AsciiString('111000000'))));
foreach ([SignatureFormat::Pkcs7Detached, SignatureFormat::Pkcs7Sha1, SignatureFormat::X509RsaSha1] as $format) {
    // ISO Table234, pages455–457 : le premier SubFilter pris en charge est sélectionné.
    $seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Filter, SignatureSeedFlag::SubFilter, SignatureSeedFlag::Reasons, SignatureSeedFlag::DigestMethod),
        $entry('Filter', new NameType('Adobe.PPKLite')), $entry('SubFilter', new ArrayType(new NameType('Unavailable.Encoding'), new NameType($format->value))),
        $entry('Reasons', new ArrayType(new TextString('Étudié et approuvé'))), $entry('DigestMethod', new ArrayType(new NameType($format === SignatureFormat::X509RsaSha1 ? 'SHA1' : 'SHA256'))), $entry('Cert', $certificate));
    $operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), new SignatureSigningIntent(reason: new TextString('Étudié et approuvé')), $seed);
    $catalog = new DocumentCatalog($pages(), $entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($operation->field)))));
    $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $operation->signature);
    // Le contrat relit les dictionnaires écrits puis vérifie le digest et le certificat du résultat CMS/PKCS#1.
    $operation->sign($prepared)->writeTo($destination . '/required-' . $format->name . '.pdf');
}

// ISO pages478/482/485 : certification première, Perms/DocMDP réel, FieldMDP copié, deuxième révision.
$author = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('author')), new SignatureCapacity(8192),
    new SignatureSigningIntent(certification: DocMdpPermissions::FormFillingTemplatesAndSigning),
    new SignatureSeedValue(entries: $entry('MDP', new SignatureSeedMdp(new IntegerType(2)))),
    new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('protected'))));
$approval = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192));
$fields = (new FieldTree())->addRoot($author->field)->addRoot(new SignatureField(new PartialFieldName(new TextString('protected'))))->addRoot($approval->field);
$catalog = new DocumentCatalog($pages(), $entry('AcroForm', new IndirectReference(new AcroForm($fields))), $entry('Perms', $author->certificationPermissions()));
$author->bindCatalog(new IndirectReference($catalog));
$first = $author->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $author->signature));
$incremental = (new IncrementalUpdate($first->revision, new FileBody($approval->signature)))->toRevision();
$second = $approval->sign((new SignatureWriter())->prepareRevision($incremental, $approval->signature));
$second->writeTo($destination . '/certification-and-approval.pdf');

// ISO page478 : Perms/DocMDP est facultatif ; le champ DocMDP seul permet la détection.
$detection = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('author')), new SignatureCapacity(8192), new SignatureSigningIntent(certification: DocMdpPermissions::FormFillingTemplatesAndSigning));
$catalog = new DocumentCatalog($pages(), $entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($detection->field)))));
$detection->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $detection->signature))->writeTo($destination . '/certification-detection.pdf');

// Table234 décrit DSA/SHA1 dans DigestMethod ; un seed Reasons seul ne crée pas cette contrainte.
// Table257, page484, permet la capacité générale DSA/SHA256 à partir de PDF1.6.
$dsaIdentity = new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents($fixtures . 'dsa-1024.der')))), file_get_contents($fixtures . 'dsa-1024-private-key.pem'));
$dsaSeed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::Reasons), $entry('Reasons', new ArrayType(new TextString('Étudié et approuvé'))));
$dsa = new SignatureFieldSigning(new OpenSslSignatureBackend($dsaIdentity), new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), new SignatureSigningIntent(reason: new TextString('Étudié et approuvé')), $dsaSeed);
$catalog = new DocumentCatalog($pages(), $entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($dsa->field)))));
$dsa->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $dsa->signature))->writeTo($destination . '/dsa-reasons.pdf');

// Adobe Table8.82/8.83, pages43–45 : P1, LockDocument et apparence explicite réellement attachée.
$appearance = new FormAppearanceStream(new ContentStreams(), $box, new DictionaryType());
$selection = new SignatureAppearanceSelection(new TextString('Explicit supplied appearance'), new IndirectReference($appearance));
$seed = new SignatureSeedValue(new SignatureSeedFlags(SignatureSeedFlag::LockDocument, SignatureSeedFlag::AppearanceFilter), $entry('LockDocument', new NameType('true')), $entry('AppearanceFilter', $selection->name));
$operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192),
    new SignatureSigningIntent(documentLock: SignatureDocumentLock::Lock, appearance: $selection), $seed,
    new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(), new IntegerType(1)));
$widget = new WidgetAnnotation($box, new WidgetAppearance($appearance));
$operation->field->addWidget($widget);
$page = new PageObject($box, new ResourceDictionary(), null, $entry('Annots', new ArrayType(new IndirectReference($widget))));
$widget->bindToPage($page);
$extensions = new DictionaryType($entry('ADBE', new DictionaryType($entry('BaseVersion', new NameType('1.7')), $entry('ExtensionLevel', new IntegerType(3)))));
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($operation->field)))), $entry('Extensions', $extensions));
$operation->bindCatalog(new IndirectReference($catalog));
$operation->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $operation->signature))->writeTo($destination . '/adobe-lock-and-appearance.pdf');

// Une préférence facultative indisponible permet un choix explicite réellement pris en charge.
$seed = new SignatureSeedValue(new SignatureSeedFlags(), $entry('SubFilter', new ArrayType(new NameType('Unavailable.Encoding'))), $entry('DigestMethod', new ArrayType(new NameType('SHA384'))));
$operation = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('approval')), new SignatureCapacity(8192), seed: $seed, format: SignatureFormat::X509RsaSha1);
$catalog = new DocumentCatalog($pages(), $entry('AcroForm', new IndirectReference(new AcroForm((new FieldTree())->addRoot($operation->field)))));
$operation->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader(), new FileBody($catalog)), $operation->signature))->writeTo($destination . '/advisory-fallback.pdf');
echo "8 native signature policy PDFs generated.\n";
