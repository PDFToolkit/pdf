<?php

declare(strict_types=1);

use PDF\FileStructure;
use PDF\Document\ResourceDictionary;
use PDF\Security as Sec;
use PDF\Serialization\FileWriter;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Filters\Crypt;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object as Obj;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create fixture output.');
}
$cases = [
    ['r2-40', Sec\HistoricalSecurityRevision::Revision2, 40, Sec\HistoricalDataCipher::Rc4, true, false],
    ['r4-rc4', Sec\HistoricalSecurityRevision::Revision4, 128, Sec\HistoricalDataCipher::Rc4, true, false],
    ['r4-aes128', Sec\HistoricalSecurityRevision::Revision4, 128, Sec\HistoricalDataCipher::Aes128, true, false],
    ['r4-aes128-metadata-clear', Sec\HistoricalSecurityRevision::Revision4, 128, Sec\HistoricalDataCipher::Aes128, false, false],
    ['r4-aes128-attachments-only', Sec\HistoricalSecurityRevision::Revision4, 128, Sec\HistoricalDataCipher::Aes128, false, true],
];
foreach (range(40, 128, 8) as $bits) {
    $cases[] = ['r3-' . $bits, Sec\HistoricalSecurityRevision::Revision3, $bits, Sec\HistoricalDataCipher::Rc4, true, false];
}
$manifest = [];
foreach ($cases as [$name, $revision, $bits, $cipher, $metadata, $attachmentOnly]) {
    foreach ([CrossReferenceFormat::Table, CrossReferenceFormat::Stream] as $format) {
        $pageMetadata = new Obj\StreamObject('<metadata>Private page XML</metadata>', new Obj\DictionaryType(
            new Entry(new Obj\NameType('Type'), new Obj\NameType('Metadata')), new Entry(new Obj\NameType('Subtype'), new Obj\NameType('XML'))), new FlateDecode());
        $rootMetadata = new Obj\StreamObject('<metadata>Document XML</metadata>', $pageMetadata->dictionary(), new FlateDecode());
        $contents = new Obj\StreamObject("% Private content bytes\n", null, new FlateDecode());
        $page = new PageObject(new Rectangle(new Obj\NumericType(0), new Obj\NumericType(0), new Obj\NumericType(200), new Obj\NumericType(100)),
            new ResourceDictionary(), $contents, new Entry(new Obj\NameType('Metadata'), new Obj\IndirectReference($pageMetadata)));
        $pages = (new PageTreeNode())->addKid($page);
        $catalog = new Obj\DictionaryType(new Entry(new Obj\NameType('Type'), new Obj\NameType('Catalog')),
            new Entry(new Obj\NameType('Pages'), new Obj\IndirectReference($pages)), new Entry(new Obj\NameType('Metadata'), new Obj\IndirectReference($rootMetadata)));
        $binary = implode('', array_map(chr(...), range(0, 255))) . "\nendstream\nendobj\n";
        $embedded = new Obj\StreamObject($binary, new Obj\DictionaryType(new Entry(new Obj\NameType('Type'), new Obj\NameType('EmbeddedFile'))), new FlateDecode());
        $specification = new Obj\DictionaryType(new Entry(new Obj\NameType('Type'), new Obj\NameType('Filespec')),
            new Entry(new Obj\NameType('F'), new LiteralString('data.bin')),
            new Entry(new Obj\NameType('EF'), new Obj\DictionaryType(new Entry(new Obj\NameType('F'), new Obj\IndirectReference($embedded)))));
        $catalog = $catalog->with(new Entry(new Obj\NameType('Names'), new Obj\DictionaryType(new Entry(new Obj\NameType('EmbeddedFiles'),
            new NameTree(new NameTreeEntry(new LiteralString('data.bin'), new Obj\IndirectReference($specification)))))));
        $body = [$catalog, new Obj\IndirectObject($embedded, new Obj\IndirectObject\ObjectIdentifier(new Obj\IndirectObject\ObjectNumber(40)))];
        if ($revision === Sec\HistoricalSecurityRevision::Revision4) {
            $body[] = new Obj\IndirectObject(new Obj\StreamObject('Explicit clear stream', null, new Crypt(), new FlateDecode()),
                new Obj\IndirectObject\ObjectIdentifier(new Obj\IndirectObject\ObjectNumber(41)));
            $body[] = new Obj\IndirectObject(new Obj\StreamObject('Explicit encrypted stream', null, new Crypt(new Obj\NameType('StdCF')), new FlateDecode()),
                new Obj\IndirectObject\ObjectIdentifier(new Obj\IndirectObject\ObjectNumber(42)));
        }
        $trailer = new Obj\DictionaryType(new Entry(new Obj\NameType('Info'), new Obj\IndirectReference(new Obj\DictionaryType(
            new Entry(new Obj\NameType('Title'), new LiteralString('Historical private title'))))), new Entry(new Obj\NameType('ID'), new Obj\ArrayType(
            new HexadecimalString(str_repeat('A', 16)), new HexadecimalString(str_repeat('B', 16)))));
        $file = new FileStructure(new FileHeader('1.7'), new FileBody(...$body), new Obj\IndirectReference($catalog), $trailer, $format);
        $filters = $attachmentOnly ? new Sec\StandardCryptFilters(new Obj\NameType('Identity'), new Obj\NameType('Identity'), new Obj\NameType('StdCF')) : new Sec\StandardCryptFilters();
        $encryption = (new Sec\HistoricalStandardSecurityHandler(aes: new Sec\OpenSslAes128Backend()))->create($revision,
            new Sec\PdfDocEncodedPassword(new HexadecimalString('user')), new Sec\PdfDocEncodedPassword(new HexadecimalString($attachmentOnly ? 'user' : 'owner')),
            new Sec\HistoricalPermissions(new Obj\IntegerType($revision === Sec\HistoricalSecurityRevision::Revision2 ? -64 : -3904), $revision),
            new Sec\FileIdentifier(new HexadecimalString(str_repeat('A', 16))), new Sec\HistoricalKeyLength(new Obj\IntegerType($bits)), $cipher, new Sec\EncryptMetadata($metadata), $filters);
        $filename = $name . '-' . strtolower($format->name) . '.pdf';
        $snapshot = (new FileWriter(encryption: $encryption))->revision($file);
        file_put_contents($destination . '/' . $filename, $snapshot->bytes);
        $infoReference = $trailer->get('Info');
        $updatedInfo = new Obj\DictionaryType(new Entry(new Obj\NameType('Title'), new LiteralString('Updated private title')));
        $updatedContents = new Obj\StreamObject("% Updated private bytes\n", null, new FlateDecode());
        $delta = (new \PDF\Syntax\FileStructure\IncrementalUpdate($snapshot, new FileBody(
            new Obj\IndirectObject($updatedInfo, $snapshot->binding($infoReference->target())->identifier),
            new Obj\IndirectObject($updatedContents, $snapshot->binding($contents)->identifier),
        )))->toRevision();
        $incrementalFilename = 'incremental-' . $filename;
        file_put_contents($destination . '/' . $incrementalFilename, $delta->bytes);
        if (!str_starts_with($delta->bytes, $snapshot->bytes)) {
            throw new LogicException('The encrypted update did not preserve the previous bytes.');
        }
        $manifest[] = ['file' => $filename, 'incrementalFile' => $incrementalFilename, 'revision' => $revision->value, 'bits' => $bits, 'metadata' => $metadata, 'attachmentOnly' => $attachmentOnly];
    }
}
file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($manifest) . " historical encryption PDFs generated.\n";
