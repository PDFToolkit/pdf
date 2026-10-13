<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Security\EncryptMetadata;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\Revision5Permissions;
use PDF\Security\Saslprep;
use PDF\Security\SystemRandomSource;
use PDF\Serialization\FileWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (($argv[1] ?? '') === 'saslprep') {
    $profile = new Saslprep();
    foreach (json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR) as $input) {
        try {
            $result = ['value' => bin2hex($profile->normalize(hex2bin($input)))];
        } catch (InvalidArgumentException) {
            $result = ['invalid' => true];
        }
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    }
    exit;
}

$destination = $argv[1];
if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
    throw new RuntimeException('Cannot create the validation output directory.');
}
foreach ([CrossReferenceFormat::Table, CrossReferenceFormat::Stream] as $format) {
    foreach ([true, false] as $encryptMetadata) {
        $font = new Type1Font(StandardFont::Helvetica);
        $resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $contents = new StreamObject(new ContentStreams(new Op\BeginTextObject(), new Op\SetTextFontAndSize(new NameType('F1'), new IntegerType(12)),
            new Op\MoveTextPosition(new IntegerType(36), new IntegerType(72)), new Op\ShowText(new LiteralString('R5 content stream')), new Op\EndTextObject()), null, new FlateDecode());
        $pageMetadata = new StreamObject('<metadata>R5 private page metadata</metadata>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))));
        $page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(100)), $resources, $contents,
            new Entry(new NameType('Metadata'), new IndirectReference($pageMetadata)));
        $pages = (new PageTreeNode())->addKid($page);
        $metadata = new StreamObject('<metadata>R5 visible metadata</metadata>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML'))));
        // A structural signature container only: no signature is computed or verified.
        $signature = new DictionaryType(new Entry(new NameType('Type'), new NameType('Sig')), new Entry(new NameType('Filter'), new NameType('Adobe.PPKLite')),
            new Entry(new NameType('ByteRange'), new ArrayType(new IntegerType(0), new IntegerType(1), new IntegerType(2), new IntegerType(3))),
            new Entry(new NameType('Contents'), new LiteralString('R5 signature container bytes')), new Entry(new NameType('Reason'), new LiteralString('R5 private signature reason')));
        $catalog = new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('Pages'), new IndirectReference($pages)),
            new Entry(new NameType('Metadata'), new IndirectReference($metadata)), new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(
                new Entry(new NameType('BaseVersion'), new NameType('1.7')), new Entry(new NameType('ExtensionLevel'), new IntegerType(3)),
            )))), new Entry(new NameType('Perms'), new DictionaryType(new Entry(new NameType('DocMDP'), new IndirectReference($signature)))));
        $information = new DictionaryType(new Entry(new NameType('Title'), new LiteralString('R5 dictionary text')), new Entry(new NameType('Nested'), new ArrayType(new LiteralString('R5 nested literal'), new HexadecimalString('R5 nested hexadecimal'))));
        $binary = new StreamObject(implode('', array_map(chr(...), range(0, 255))) . "\nendstream\nendobj\n", null, new FlateDecode());
        $body = [new IndirectObject($binary, new ObjectIdentifier(new ObjectNumber(40))), new IndirectObject(new LiteralString('R5 standalone string'), new ObjectIdentifier(new ObjectNumber(41))), $catalog];
        if ($format === CrossReferenceFormat::Stream) {
            $body[] = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(22), $information), new ObjectStreamEntry(new ObjectNumber(23), $font), new FlateDecode());
        }
        $trailer = new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($information)), new Entry(new NameType('ID'), new ArrayType(new HexadecimalString(str_repeat('A', 16)), new HexadecimalString(str_repeat('B', 16)))));
        $file = new FileStructure(new FileHeader('1.7'), new FileBody(...$body), new IndirectReference($catalog), $trailer, $format);
        $encryption = (new HistoricalRevision5SecurityHandler(new OpenSslAes256Backend(), new SystemRandomSource()))->create(
            (new Saslprep())->prepare('user'), (new Saslprep())->prepare('owner'), new Revision5Permissions(-3904), new EncryptMetadata($encryptMetadata),
        );
        $name = ($format === CrossReferenceFormat::Table ? 'table' : 'stream') . ($encryptMetadata ? '-metadata-encrypted' : '-metadata-clear') . '.pdf';
        file_put_contents($destination . '/' . $name, (new FileWriter(encryption: $encryption))->serialize($file));
        if ($format === CrossReferenceFormat::Table && $encryptMetadata) {
            file_put_contents($destination . '/plain.pdf', (new FileWriter())->serialize($file));
        }
    }
}
