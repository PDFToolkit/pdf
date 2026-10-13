<?php

declare(strict_types=1);

use PDF\Document\Extensions\DeveloperExtension;
use PDF\Document\Extensions\DeveloperExtensionEntry;
use PDF\Document\Extensions\DeveloperExtensions;
use PDF\Document\Extensions\DeveloperPrefix;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Security as Sec;
use PDF\Serialization\FileWriter;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Filters\Crypt;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object as Obj;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create public-key fixture output.');
}
$certificate = new Sec\X509Certificate(new HexadecimalString(file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/signing/signer.der')));
$attachmentCertificate = new Sec\X509Certificate(new HexadecimalString(file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/signing/recipient.der')));
$permissions = new Sec\PublicKeyPermissions(new Obj\IntegerType(0x0104));
$group = new Sec\PublicKeyRecipientGroup($permissions, $certificate);
$attachmentGroup = new Sec\PublicKeyRecipientGroup(null, $attachmentCertificate);
$extensions = new DeveloperExtensions(new DeveloperExtensionEntry(new DeveloperPrefix(new Obj\NameType('ADBE')),
    new DeveloperExtension(new Obj\NameType('1.7'), new Obj\IntegerType(3))));
$manifest = [];
foreach (Sec\PublicKeyPermissionByteOrder::cases() as $order) {
    $handler = new Sec\PublicKeySecurityHandler(new Sec\OpenSslCmsEnvelopeBackend(), aes128: new Sec\OpenSslAes128Backend(),
        aes256: new Sec\OpenSslAes256Backend(), permissionByteOrder: $order);
    $legacy = [
        's3-40' => $handler->createLegacy(Sec\PublicKeySubFilter::Pkcs7S3, new Sec\PublicKeyKeyLength(new Obj\IntegerType(40)), $permissions, $group),
        's4-128' => $handler->createLegacy(Sec\PublicKeySubFilter::Pkcs7S4, new Sec\PublicKeyKeyLength(new Obj\IntegerType(128)), $permissions, $group),
    ];
    $operations = [];
    foreach ($legacy as $name => $operation) {
        $operations[$name] = [$operation, true, false];
    }
    foreach ([Sec\PublicKeyCipher::Rc4, Sec\PublicKeyCipher::Aes128, Sec\PublicKeyCipher::Aes256] as $cipher) {
        foreach ([true, false] as $encryptMetadata) {
            $bits = $cipher === Sec\PublicKeyCipher::Aes256 ? 256 : 128;
            $default = $handler->createFilter(new Obj\NameType('DefaultCryptFilter'), $cipher, new Sec\PublicKeyKeyLength(new Obj\IntegerType($bits)),
                new Sec\EncryptMetadata($encryptMetadata), Sec\PublicKeyRecipientScope::Document, new Obj\NameType('DocOpen'), $group);
            $attachment = $handler->createFilter(new Obj\NameType('AttachmentKey'), $cipher, new Sec\PublicKeyKeyLength(new Obj\IntegerType($bits)),
                new Sec\EncryptMetadata(true), Sec\PublicKeyRecipientScope::IndividualStream, new Obj\NameType('EFOpen'), $attachmentGroup);
            $operation = new Sec\PublicKeyEncryption(new Sec\PublicKeyFilterConfiguration(new Obj\NameType('DefaultCryptFilter'), new Obj\NameType('DefaultCryptFilter'),
                new Obj\NameType('AttachmentKey'), $default, $attachment), $permissions);
            $operations['s5-' . strtolower($cipher->name) . ($encryptMetadata ? '' : '-metadata-clear')] = [$operation, $encryptMetadata, true];
        }
    }
    foreach ($operations as $name => [$operation, $encryptMetadata, $hasAttachment]) {
        foreach (CrossReferenceFormat::cases() as $xref) {
            $metadata = new Obj\StreamObject('<metadata>Public-key document XML</metadata>', new Obj\DictionaryType(
                new Entry(new Obj\NameType('Type'), new Obj\NameType('Metadata')), new Entry(new Obj\NameType('Subtype'), new Obj\NameType('XML'))), new FlateDecode());
            $contents = new Obj\StreamObject("% Private public-key content\n", null, new FlateDecode());
            $page = new PageObject(new Rectangle(new Obj\NumericType(0), new Obj\NumericType(0), new Obj\NumericType(200), new Obj\NumericType(100)),
                new ResourceDictionary(), $contents);
            $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new Obj\NameType('Extensions'), $extensions),
                new Entry(new Obj\NameType('Metadata'), new Obj\IndirectReference($metadata)));
            $info = new Obj\DictionaryType(new Entry(new Obj\NameType('Title'), new LiteralString('Public-key private title')));
            $trailer = new Obj\DictionaryType(new Entry(new Obj\NameType('Info'), new Obj\IndirectReference($info)), new Entry(new Obj\NameType('ID'),
                new Obj\ArrayType(new HexadecimalString(str_repeat('A', 16)), new HexadecimalString(str_repeat('B', 16)))));
            $body = [$catalog];
            if ($hasAttachment) {
                $body[] = new Obj\IndirectObject(new Obj\StreamObject(implode('', array_map(chr(...), range(0, 255))),
                    new Obj\DictionaryType(new Entry(new Obj\NameType('Type'), new Obj\NameType('EmbeddedFile'))), new FlateDecode()),
                    new Obj\IndirectObject\ObjectIdentifier(new Obj\IndirectObject\ObjectNumber(40)));
                $body[] = new Obj\IndirectObject(new Obj\StreamObject('Explicit public-key clear stream', null, new Crypt(), new FlateDecode()),
                    new Obj\IndirectObject\ObjectIdentifier(new Obj\IndirectObject\ObjectNumber(41)));
            }
            $file = new FileStructure(new FileHeader(), new FileBody(...$body), new Obj\IndirectReference($catalog), $trailer, $xref);
            $snapshot = (new FileWriter(encryption: $operation))->revision($file);
            $filename = $order->name . '-' . $name . '-' . strtolower($xref->name) . '.pdf';
            $snapshot->writeTo($destination . '/' . $filename);
            $updated = new Obj\StreamObject("% Updated public-key content\n", null, new FlateDecode());
            $delta = (new IncrementalUpdate($snapshot, new FileBody(new Obj\IndirectObject($updated, $snapshot->binding($contents)->identifier))))->toRevision();
            $delta->writeTo($destination . '/incremental-' . $filename);
            $manifest[] = ['file' => $filename, 'incrementalFile' => 'incremental-' . $filename, 'permissionByteOrder' => $order->value,
                'permissions' => $permissions->unsigned, 'metadata' => $encryptMetadata, 'attachment' => $hasAttachment];
        }
    }
}
file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($manifest) . " initial and incremental public-key PDFs generated.\n";
