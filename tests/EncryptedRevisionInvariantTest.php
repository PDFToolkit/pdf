<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalPermissions;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardEncryption;
use PDF\Security\HistoricalStandardSecurityHandler;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Serialization\FileWriter;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptedRevisionInvariantTest extends TestCase
{
    /** @return iterable<string, array{HistoricalDataCipher, CrossReferenceFormat}> */
    public static function variants(): iterable
    {
        foreach (HistoricalDataCipher::cases() as $cipher) {
            foreach (CrossReferenceFormat::cases() as $format) {
                yield $cipher->name . $format->name => [$cipher, $format];
            }
        }
    }

    private function encryption(HistoricalDataCipher $cipher, HistoricalSecurityRevision $revision = HistoricalSecurityRevision::Revision4): HistoricalStandardEncryption
    {
        return (new HistoricalStandardSecurityHandler(aes: new OpenSslAes128Backend()))->create(
            $revision,
            new PdfDocEncodedPassword(new HexadecimalString('user')),
            new PdfDocEncodedPassword(new HexadecimalString('owner')),
            new HistoricalPermissions(new IntegerType($revision === HistoricalSecurityRevision::Revision2 ? -64 : -4), $revision),
            new FileIdentifier(new HexadecimalString(str_repeat('i', 16))),
            new HistoricalKeyLength(new IntegerType($revision === HistoricalSecurityRevision::Revision2 ? 40 : 128)),
            $cipher,
            new EncryptMetadata($revision === HistoricalSecurityRevision::Revision2),
        );
    }

    private function file(DictionaryType $catalog, CrossReferenceFormat $format): FileStructure
    {
        $id = new HexadecimalString(str_repeat('i', 16));
        return new FileStructure(new FileHeader(), new FileBody($catalog), trailerEntries: new DictionaryType(
            new Entry(new NameType('ID'), new ArrayType($id, $id)),
        ), crossReferenceFormat: $format);
    }

    #[DataProvider('variants')]
    public function testMetadataExemptionFollowsEffectiveReplacementAndHistoricalAliases(HistoricalDataCipher $cipher, CrossReferenceFormat $format): void
    {
        $metadata = new StreamObject('<old/>', new DictionaryType(
            new Entry(new NameType('Type'), new NameType('Metadata')),
            new Entry(new NameType('Subtype'), new NameType('XML')),
        ));
        $pages = new PageTreeNode();
        $catalog = new DocumentCatalog($pages, new Entry(new NameType('Metadata'), new IndirectReference($metadata)));
        $base = (new FileWriter(encryption: $this->encryption($cipher)))->revision($this->file($catalog, $format));
        $replacement = new StreamObject('<new/>', $metadata->dictionary());
        $changed = (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($metadata)->identifier))))->toRevision();
        $span = $changed->span($replacement);
        $object = substr($changed->bytes, $span->offset->value, $span->length);
        self::assertStringContainsString('<new/>', $object);
        self::assertStringContainsString('/Name /Identity', $object);
        self::assertSame($replacement, $changed->reference($metadata)->target());

        // The new catalog deliberately retains the old PHP alias with an explicit PDF identity.
        $newCatalog = new DocumentCatalog($pages, new Entry(new NameType('Metadata'), new IndirectReference($metadata, $base->binding($metadata)->identifier)));
        $latest = new StreamObject('<latest/>', $metadata->dictionary());
        $last = (new IncrementalUpdate($changed, new FileBody(
            new IndirectObject($latest, $changed->binding($replacement)->identifier),
            new IndirectObject($newCatalog, $changed->binding($catalog)->identifier),
        )))->toRevision();
        $span = $last->span($latest);
        self::assertStringContainsString('<latest/>', substr($last->bytes, $span->offset->value, $span->length));
        self::assertStringStartsWith($changed->bytes, $last->bytes);
    }

    public function testObjectStreamCannotReplaceTheEncryptNumberUsingAnotherDictionaryIdentity(): void
    {
        $encryption = $this->encryption(HistoricalDataCipher::Rc4);
        $base = (new FileWriter(encryption: $encryption))->revision($this->file(new DocumentCatalog(new PageTreeNode()), CrossReferenceFormat::Stream));
        $copy = new DictionaryType(...iterator_to_array($encryption->dictionary()->entries(), false));
        $packed = new ObjectStream(new ObjectStreamEntry($base->binding($encryption->dictionary())->identifier->objectNumber, $copy));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('including object-stream members');
        (new IncrementalUpdate($base, new FileBody($packed)))->toRevision();
    }

    public function testRevisionTwoRejectsPdf10(): void
    {
        $encryption = $this->encryption(HistoricalDataCipher::Rc4, HistoricalSecurityRevision::Revision2);
        $this->expectException(\LogicException::class);
        $encryption->validateFile(new FileHeader('1.0'), new DocumentCatalog(new PageTreeNode()), $encryption->identifier);
    }

    public function testRevisionTwoAcceptsTheNormativePdf11Introduction(): void
    {
        $encryption = $this->encryption(HistoricalDataCipher::Rc4, HistoricalSecurityRevision::Revision2);
        $encryption->validateFile(new FileHeader('1.1'), new DocumentCatalog(new PageTreeNode()), $encryption->identifier);
        self::assertSame('1.1', (new FileHeader('1.1'))->version->value);
    }
}
