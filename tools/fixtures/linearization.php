<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\Destinations\Fit;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileEncryption;
use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalPermissions;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardSecurityHandler;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Security\PreparedPassword;
use PDF\Security\Revision5Permissions;
use PDF\Security\SystemRandomSource;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Linearization\HintStreamSplit;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

$directory = $argv[1] ?? throw new InvalidArgumentException('Pass an existing local output directory.');
if (!is_dir($directory) || !is_writable($directory)) {
    throw new InvalidArgumentException('The output directory must exist and be writable.');
}

/** @return array{FileStructure, list<DictionaryType>} */
function linearizationFixture(CrossReferenceFormat $format, bool $packed, int $initial): array
{
    $firstFont = new Type1Font(StandardFont::Helvetica);
    $laterFont = new Type1Font(StandardFont::Courier);
    $firstResources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($firstFont)))));
    $laterResources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($firstFont)), new Entry(new NameType('F2'), new IndirectReference($laterFont)))));
    $pages = new PageTreeNode(new Entry(new NameType('MediaBox'), new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(300), new IntegerType(400))), new Entry(new NameType('Resources'), new IndirectReference($firstResources)));
    $leaves = [];
    foreach ([0, 1, 2] as $index) {
        $entries = [new Entry(new NameType('PrivateData'), new LiteralString('Private page ' . $index))];
        if ($index !== 1) {
            $thumb = new StreamObject(chr(40 + $index * 40), new DictionaryType(new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('Image')), new Entry(new NameType('Width'), new IntegerType(1)), new Entry(new NameType('Height'), new IntegerType(1)), new Entry(new NameType('BitsPerComponent'), new IntegerType(8)), new Entry(new NameType('ColorSpace'), new NameType('DeviceGray'))));
            $entries[] = new Entry(new NameType('Thumb'), new IndirectReference($thumb));
        }
        if ($index === 1) {
            $entries[] = new Entry(new NameType('Metadata'), new IndirectReference(new StreamObject('<page-metadata/>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))))));
        }
        $leaf = new PageObject(null, $index === 0 ? null : new IndirectReference($laterResources), new StreamObject('BT /F1 12 Tf 20 300 Td (Native page ' . $index . ') Tj ET', filters: new FlateDecode()), ...$entries);
        $pages->addKid($leaf);
        $leaves[] = $leaf;
    }
    $outline = new OutlineDictionary();
    foreach ($leaves as $index => $leaf) {
        $outline->addItem(new OutlineItem(new TextString('Page ' . $index), new Fit($leaf)));
    }
    $attachment = new StreamObject("Native attachment\0\xff", new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile'))), new FlateDecode());
    $filespec = new DictionaryType(new Entry(new NameType('Type'), new NameType('Filespec')), new Entry(new NameType('F'), new LiteralString('sample.bin')), new Entry(new NameType('EF'), new DictionaryType(new Entry(new NameType('F'), new IndirectReference($attachment)))));
    $nameTree = new NameTree(new NameTreeEntry(new LiteralString('sample.bin'), new IndirectReference($filespec)));
    $metadata = new StreamObject('<document-metadata/>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))));
    $catalog = new DocumentCatalog($pages, new Entry(new NameType('Outlines'), new IndirectReference($outline)), new Entry(new NameType('OpenAction'), new Fit($leaves[$initial])), new Entry(new NameType('Metadata'), new IndirectReference($metadata)), new Entry(new NameType('Names'), new DictionaryType(new Entry(new NameType('EmbeddedFiles'), new IndirectReference($nameTree)))), new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3)))))));
    $info = new DictionaryType(new Entry(new NameType('Title'), new LiteralString('Native private title')));
    $identifier = new HexadecimalString(str_repeat('i', 16));
    $trailer = new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($info)), new Entry(new NameType('ID'), new ArrayType($identifier, $identifier)));
    $body = new FileBody($catalog);
    if ($packed) {
        $parts = [];
        foreach ([$firstFont, $laterFont, $firstResources, $laterResources, $pages, $outline, $catalog, $info, $leaves[0], $leaves[2]] as $index => $value) {
            $parts[] = new ObjectStreamEntry(new ObjectNumber(101 + $index), $value);
        }
        $body->addObject(new ObjectStream(...[...$parts, new FlateDecode()]));
    }
    return [new FileStructure(new FileHeader('1.7'), $body, new IndirectReference($catalog), $trailer, $format), $leaves];
}

/** @return list<array{string, ?FileEncryption}> */
function linearizationSecurityCases(): array
{
    $cases = [['plain', null]];
    $identifier = new FileIdentifier(new HexadecimalString(str_repeat('i', 16)));
    foreach (HistoricalSecurityRevision::cases() as $revision) {
        foreach ($revision === HistoricalSecurityRevision::Revision4 ? [true, false] : [true] as $metadata) {
            foreach ($revision === HistoricalSecurityRevision::Revision4 ? [HistoricalDataCipher::Rc4, HistoricalDataCipher::Aes128] : [HistoricalDataCipher::Rc4] as $cipher) {
                $operation = (new HistoricalStandardSecurityHandler(aes: new OpenSslAes128Backend()))->create($revision, new PdfDocEncodedPassword(new HexadecimalString('user')), new PdfDocEncodedPassword(new HexadecimalString('owner')), new HistoricalPermissions(new IntegerType(-4), $revision), $identifier, new HistoricalKeyLength(new IntegerType($revision === HistoricalSecurityRevision::Revision2 ? 40 : 128)), $cipher, new EncryptMetadata($metadata));
                $cases[] = ['r' . $revision->value . '-' . $cipher->value . '-' . (int) $metadata, $operation];
            }
        }
    }
    foreach ([true, false] as $metadata) {
        $cases[] = ['r5-' . (int) $metadata, (new HistoricalRevision5SecurityHandler(new OpenSslAes256Backend(), new SystemRandomSource()))->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata($metadata))];
    }
    return $cases;
}

$manifest = [];
foreach (linearizationSecurityCases() as [$name, $encryption]) {
    foreach (CrossReferenceFormat::cases() as $format) {
        foreach ($format !== CrossReferenceFormat::Table ? [false, true] : [false] as $packed) {
            foreach ([null, 1, 17, 50] as $boundary) {
                [$file] = linearizationFixture($format, $packed, 0);
                $filename = $name . '-' . $format->name . '-' . (int) $packed . '-' . ($boundary ?? 'primary') . '.pdf';
                $writer = new LinearizedWriter(hintSplit: $boundary === null ? null : new HintStreamSplit(new IntegerType($boundary)), encryption: $encryption);
                file_put_contents($directory . '/' . $filename, $writer->serialize($file));
                $manifest[] = ['file' => $filename, 'password' => $encryption === null ? '' : 'user', 'firstPage' => 0, 'metadata' => $encryption?->metadata()->value ?? true];
            }
        }
    }
}
foreach (CrossReferenceFormat::cases() as $format) {
    [$file] = linearizationFixture($format, $format !== CrossReferenceFormat::Table, 2);
    $filename = 'arbitrary-first-' . $format->name . '.pdf';
    file_put_contents($directory . '/' . $filename, (new LinearizedWriter(hintSplit: new HintStreamSplit(new IntegerType(1))))->serialize($file));
    $manifest[] = ['file' => $filename, 'password' => '', 'firstPage' => 2, 'metadata' => true];
}
file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
echo count($manifest), ' native linearized files', PHP_EOL;
