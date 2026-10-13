<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalPermissions;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardSecurityHandler;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Serialization\LinearizedWriter;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Linearization\HintStreamSplit;
use PDF\Syntax\Linearization\ThumbnailGraphNormalization;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

$directory = $argv[1] ?? throw new InvalidArgumentException('Pass an existing local output directory.');
if (!is_dir($directory) || !is_writable($directory)) {
    throw new InvalidArgumentException('The output directory must exist and be writable.');
}

function graphFixture(CrossReferenceFormat $format, bool $mixedRoot, bool $sharedEmbedded, bool $privateFirst, bool $includePrivate): FileStructure
{
    $colour = new ArrayType(new NameType('CalRGB'), new DictionaryType(new Entry(new NameType('WhitePoint'), new ArrayType(new IntegerType(1), new IntegerType(1), new IntegerType(1)))));
    $metadata = new StreamObject('<graph-metadata>private thumbnail metadata</graph-metadata>', new DictionaryType(new Entry(new NameType('Type'), new NameType('Metadata')), new Entry(new NameType('Subtype'), new NameType('XML')), new Entry(new NameType('PrivateLabel'), new LiteralString('Graph metadata label'))));
    $thumbnail = new StreamObject("\xff\0\0", new DictionaryType(
        new Entry(new NameType('Type'), new NameType('XObject')),
        new Entry(new NameType('Subtype'), new NameType('Image')),
        new Entry(new NameType('Width'), new IntegerType(1)),
        new Entry(new NameType('Height'), new IntegerType(1)),
        new Entry(new NameType('ColorSpace'), new IndirectReference($colour)),
        new Entry(new NameType('BitsPerComponent'), new IntegerType(8)),
        new Entry(new NameType('Metadata'), new IndirectReference($metadata)),
        new Entry(new NameType('PrivateLabel'), new LiteralString('Graph thumbnail label')),
    ), new FlateDecode());
    $resources = [new Entry(new NameType('ColorSpace'), new DictionaryType(new Entry(new NameType('CS'), new IndirectReference($colour))))];
    if ($mixedRoot) {
        $resources[] = new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Im'), new IndirectReference($thumbnail))));
    }
    $pages = new PageTreeNode(new Entry(new NameType('MediaBox'), new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100))), new Entry(new NameType('Resources'), new IndirectReference(new ResourceDictionary(...$resources))));
    $params = new DictionaryType(new Entry(new NameType('Size'), new IntegerType(13)), new Entry(new NameType('PrivateLabel'), new LiteralString('Shared EF params')));
    for ($index = 0; $index < 3; ++$index) {
        $entries = $index === 1 ? [] : [new Entry(new NameType('Thumb'), new IndirectReference($thumbnail))];
        if ($index === 1 && $sharedEmbedded) {
            $entries[] = new Entry(new NameType('PrivateParams'), new IndirectReference($params));
        }
        $pages->addKid(new PageObject(null, null, new StreamObject($mixedRoot ? 'q 10 0 0 10 0 0 cm /Im Do Q' : 'q Q'), ...$entries));
    }
    $private = new StreamObject('Private payload', new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile'))), new FlateDecode());
    $payloads = [$private];
    if ($sharedEmbedded) {
        $first = new StreamObject('First payload', new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile')), new Entry(new NameType('Params'), new IndirectReference($params))), new FlateDecode());
        $second = new StreamObject('Second payload', new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile')), new Entry(new NameType('Params'), new IndirectReference($params))), new FlateDecode());
        $payloads = !$includePrivate ? [$first, $second] : ($privateFirst ? [$private, $first, $second] : [$first, $second, $private]);
    }
    $filespecs = [];
    foreach ($payloads as $index => $payload) {
        $filespecs[] = new IndirectReference(new DictionaryType(new Entry(new NameType('Type'), new NameType('Filespec')), new Entry(new NameType('F'), new LiteralString('graph-' . $index . '.bin')), new Entry(new NameType('EF'), new DictionaryType(new Entry(new NameType('F'), new IndirectReference($payload))))));
    }
    $catalog = new DocumentCatalog($pages, new Entry(new NameType('Metadata'), new IndirectReference($metadata)), new Entry(new NameType('PrivateFiles'), new ArrayType(...$filespecs)));
    $identifier = new HexadecimalString(str_repeat('g', 16));
    $trailer = new DictionaryType(new Entry(new NameType('ID'), new ArrayType($identifier, $identifier)));
    return new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog), $trailer, $format);
}

$manifest = [];
foreach ([false, true] as $encrypted) {
    $encryption = $encrypted ? (new HistoricalStandardSecurityHandler(aes: new OpenSslAes128Backend()))->create(
        HistoricalSecurityRevision::Revision4,
        new PdfDocEncodedPassword(new HexadecimalString('user')),
        new PdfDocEncodedPassword(new HexadecimalString('owner')),
        new HistoricalPermissions(new IntegerType(-4), HistoricalSecurityRevision::Revision4),
        new FileIdentifier(new HexadecimalString(str_repeat('g', 16))),
        new HistoricalKeyLength(new IntegerType(128)),
        HistoricalDataCipher::Aes128,
        new EncryptMetadata(false),
    ) : null;
    foreach (CrossReferenceFormat::cases() as $format) {
        foreach ([false, true] as $mixedRoot) {
            foreach ([false, true] as $sharedEmbedded) {
                foreach ($sharedEmbedded ? ['none', 'first', 'last'] : ['last'] as $privatePosition) {
                    $privateFirst = $privatePosition === 'first';
                    $includePrivate = $privatePosition !== 'none';
                    foreach ([null, 1] as $boundary) {
                        $file = graphFixture($format, $mixedRoot, $sharedEmbedded, $privateFirst, $includePrivate);
                        $before = $file->toPdf();
                        $writer = new LinearizedWriter(hintSplit: $boundary === null ? null : new HintStreamSplit(new IntegerType($boundary)), encryption: $encryption, thumbnailNormalization: new ThumbnailGraphNormalization());
                        $bytes = $writer->serialize($file);
                        if ($before !== $file->toPdf()) {
                            throw new LogicException('The original file graph must remain unchanged.');
                        }
                        $name = (int) $encrypted . '-' . $format->name . '-' . (int) $mixedRoot . '-' . (int) $sharedEmbedded . '-' . $privatePosition . '-' . ($boundary ?? 'primary') . '.pdf';
                        file_put_contents($directory . '/' . $name, $bytes);
                        $manifest[] = ['file' => $name, 'password' => $encrypted ? 'user' : '', 'mixedRoot' => $mixedRoot, 'sharedEmbedded' => $sharedEmbedded, 'privateFirst' => $privateFirst, 'includePrivate' => $includePrivate];
                    }
                }
            }
        }
    }
}
file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
echo count($manifest), ' normalized linearization graph fixtures', PHP_EOL;
