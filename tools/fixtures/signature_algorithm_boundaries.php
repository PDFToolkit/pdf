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
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create signature algorithm fixture output.');
}
$fixtures = dirname(__DIR__, 2) . '/tests/Fixtures/signing/';
// sourcePDF32000_2008.pdf, Table257, page484: key sizes and actual digest minimum versions.
$cases = [
    ['rsa-1024', SignatureFormat::X509RsaSha1, '1.3'],
    ['rsa-1024', SignatureFormat::Pkcs7Detached, '1.6'],
    ['dsa-1024', SignatureFormat::Pkcs7Detached, '1.6'],
    ['dsa-1024', SignatureFormat::Pkcs7Sha1, '1.6'],
];
foreach ($cases as [$name, $format, $version]) {
    $identity = new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString(file_get_contents($fixtures . $name . '.der')))), file_get_contents($fixtures . $name . '-private-key.pem'));
    $entries = $format === SignatureFormat::X509RsaSha1 ? [new Entry(new NameType('Cert'), $identity->chain->object())] : [];
    $signature = new PendingSignature(new SignatureCapacity(8192), $format, new NameType('Adobe.PPKLite'), ...$entries);
    $fields = (new FieldTree())->addRoot(new SignatureField(new PartialFieldName(new TextString('approval')), value: new IndirectReference($signature)));
    $pages = (new PageTreeNode())->addKid(new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100)), new ResourceDictionary()));
    $catalog = new DocumentCatalog($pages, new Entry(new NameType('AcroForm'), new IndirectReference(new AcroForm($fields))));
    $signed = (new SignatureWriter())->prepare(new FileStructure(new FileHeader($version), new FileBody($catalog)), $signature)->sign(new OpenSslSignatureBackend($identity));
    $signed->writeTo($destination . '/' . $name . '-' . $format->name . '.pdf');
}
echo "4 native algorithm boundary PDFs generated.\n";
