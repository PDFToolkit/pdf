<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Security\EncryptionVersionPolicy;
use PDF\Security\EncryptMetadata;
use PDF\Security\HistoricalRevision5SecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\PreparedPassword;
use PDF\Security\Revision5Permissions;
use PDF\Security\SystemRandomSource;
use PDF\Serialization\FileWriter;
use PDF\Specification\FeatureApplicability;
use PDF\Specification\FeatureStatus;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Version;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Pdf20VersionPolicyTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function downgradedVersions(): iterable
    {
        yield 'Version removed' => [null];
        yield 'Version lowered' => ['1.7'];
    }

    #[DataProvider('downgradedVersions')]
    public function testIncrementalVersionUsesTheFrozenPrecedingCatalog(?string $newVersion): void
    {
        $pages = new PageTreeNode();
        $catalog = new DocumentCatalog($pages, new Version(new NameType('2.0')));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(PdfVersion::PDF17), new FileBody($catalog)));
        $replacement = new DocumentCatalog($pages, ...($newVersion === null ? [] : [new Version(new NameType($newVersion))]));
        $update = new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($catalog)->identifier)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot lower the effective version');
        $update->toRevision();
    }

    #[DataProvider('formats')]
    public function testARevisionCanUpgradeAndRetainPdf20InEveryCrossReferenceFormat(CrossReferenceFormat $format): void
    {
        $pages = new PageTreeNode();
        $catalog = new DocumentCatalog($pages);
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: $format));
        $replacement = new DocumentCatalog($pages, new Version(new NameType('2.0')));
        $upgraded = (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($catalog)->identifier))))->toRevision();
        $last = (new IncrementalUpdate($upgraded, new FileBody()))->toRevision();
        self::assertStringStartsWith($base->bytes, $upgraded->bytes);
        self::assertStringStartsWith($upgraded->bytes, $last->bytes);
        self::assertSame(PdfVersion::PDF20, (new EffectivePdfVersion($last->header, $last->binding($last->root->target())->dictionary))->value);
    }

    public function testDeprecationRemainsDistinctFromTheExplicitRevisionFiveProhibition(): void
    {
        $version = new EffectivePdfVersion(new FileHeader(PdfVersion::PDF20), new DictionaryType());
        $deprecated = new FeatureApplicability(PdfVersion::PDF10, deprecatedIn: PdfVersion::PDF20);
        $deprecated->validate($version, 'A deprecated feature');
        self::assertSame(FeatureStatus::Deprecated, $deprecated->status(PdfVersion::PDF20));
        $policy = new EncryptionVersionPolicy();
        // Deprecated R=4 is not the prohibited R=5 value in Table21.
        $policy->validate($version, new DictionaryType(new Entry(new NameType('Filter'), new NameType('Standard')), new Entry(new NameType('R'), new IntegerType(4)), new Entry(new NameType('P'), new IntegerType(-4))));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('revision 5 is prohibited');
        $policy->validate($version, new DictionaryType(new Entry(new NameType('Filter'), new NameType('Standard')), new Entry(new NameType('R'), new IntegerType(5))));
    }

    #[DataProvider('formats')]
    public function testHistoricalR5StillWritesPdf17ButCannotBeUpgradedToPdf20(CrossReferenceFormat $format): void
    {
        $encryption = (new HistoricalRevision5SecurityHandler(new OpenSslAes256Backend(), new SystemRandomSource()))
            ->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(true));
        $pages = new PageTreeNode();
        $extensions = new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(
            new Entry(new NameType('BaseVersion'), new NameType('1.7')),
            new Entry(new NameType('ExtensionLevel'), new IntegerType(3)),
        ))));
        $catalog = new DocumentCatalog($pages, $extensions);
        $id = new HexadecimalString(str_repeat('i', 16));
        $file = new FileStructure(new FileHeader(), new FileBody($catalog), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id, $id))), crossReferenceFormat: $format);
        $base = (new FileWriter(encryption: $encryption))->revision($file);
        self::assertStringStartsWith('%PDF-1.7', $base->bytes);
        $replacement = $catalog->with(new Version(new NameType('2.0')));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('revision 5 is prohibited');
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($replacement, $base->binding($catalog)->identifier))))->toRevision();
    }

    /** @return iterable<string, array{int}> */
    public static function invalidStandardPermissions(): iterable
    {
        yield 'outside signed 32 bits' => [-4294967300];
        yield 'accessibility bit cleared' => [-516];
        yield 'low reserved bit set' => [-3];
        yield 'reserved bit7 cleared' => [-68];
        yield 'high reserved bit13 cleared' => [-4100];
    }

    #[DataProvider('invalidStandardPermissions')]
    public function testPdf20RejectsInvalidStandardPermissionBits(int $word): void
    {
        $version = new EffectivePdfVersion(new FileHeader(PdfVersion::PDF20), new DictionaryType());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('accessibility bit10');
        (new EncryptionVersionPolicy())->validate($version, new DictionaryType(new Entry(new NameType('Filter'), new NameType('Standard')), new Entry(new NameType('R'), new IntegerType(4)), new Entry(new NameType('P'), new IntegerType($word))));
    }

    private function historicalAes128(): \PDF\Security\HistoricalStandardEncryption
    {
        $revision = \PDF\Security\HistoricalSecurityRevision::Revision4;
        return (new \PDF\Security\HistoricalStandardSecurityHandler(aes: new \PDF\Security\OpenSslAes128Backend()))->create(
            $revision,
            new \PDF\Security\PdfDocEncodedPassword(new HexadecimalString('user')),
            new \PDF\Security\PdfDocEncodedPassword(new HexadecimalString('owner')),
            new \PDF\Security\HistoricalPermissions(new IntegerType(-4), $revision),
            new \PDF\Security\FileIdentifier(new HexadecimalString(str_repeat('i', 16))),
            new \PDF\Security\HistoricalKeyLength(new IntegerType(128)),
            \PDF\Security\HistoricalDataCipher::Aes128,
            new EncryptMetadata(true),
        );
    }

    public function testPdf20RejectsAnEncryptedCatalogInsideAnObjectStream(): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $packed = new \PDF\Syntax\FileStructure\ObjectStream(new \PDF\Syntax\FileStructure\ObjectStreamEntry(new \PDF\Syntax\Object\IndirectObject\ObjectNumber(8), $catalog));
        $id = new HexadecimalString(str_repeat('i', 16));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('encrypted PDF 2.0 catalog cannot be embedded');
        (new FileWriter(encryption: $this->historicalAes128()))->serialize(new FileStructure(new FileHeader(PdfVersion::PDF20), new FileBody($packed), new \PDF\Syntax\Object\IndirectReference($catalog), new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id, $id))), CrossReferenceFormat::Stream));
    }

    public function testHybridPdf20WritesTheEncryptedCatalogOutsideItsLogicalContainer(): void
    {
        $pages = new PageTreeNode();
        $catalog = new DocumentCatalog($pages);
        $packed = new \PDF\Syntax\FileStructure\ObjectStream(new \PDF\Syntax\FileStructure\ObjectStreamEntry(new \PDF\Syntax\Object\IndirectObject\ObjectNumber(8), $catalog));
        $id = new HexadecimalString(str_repeat('i', 16));
        $base = (new FileWriter(encryption: $this->historicalAes128()))->revision(new FileStructure(new FileHeader(PdfVersion::PDF20), new FileBody($packed), new \PDF\Syntax\Object\IndirectReference($catalog), new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id, $id))), CrossReferenceFormat::Hybrid));
        $rootNumber = $base->binding($catalog)->identifier->objectNumber->value;
        self::assertArrayNotHasKey($rootNumber, $base->compressedObjects());
        $replacement = new DocumentCatalog($pages);
        $newContainer = new \PDF\Syntax\FileStructure\ObjectStream(new \PDF\Syntax\FileStructure\ObjectStreamEntry($base->binding($catalog)->identifier->objectNumber, $replacement));
        $updated = (new IncrementalUpdate($base, new FileBody($newContainer)))->toRevision();
        self::assertArrayNotHasKey($rootNumber, $updated->compressedObjects());
        self::assertStringStartsWith($base->bytes, $updated->bytes);
    }

    public function testPdf17HistoricalPermissionsAreNotReinterpretedAsPdf20(): void
    {
        $version = new EffectivePdfVersion(new FileHeader(PdfVersion::PDF17), new DictionaryType());
        (new EncryptionVersionPolicy())->validate($version, new DictionaryType(new Entry(new NameType('Filter'), new NameType('Standard')), new Entry(new NameType('R'), new IntegerType(4)), new Entry(new NameType('P'), new IntegerType(-516))));
        self::assertSame(PdfVersion::PDF17, $version->value);
    }

    public function testAnInitialPdf20FileRejectsHistoricalR5DespiteAnAdobeExtension(): void
    {
        $encryption = (new HistoricalRevision5SecurityHandler(new OpenSslAes256Backend(), new SystemRandomSource()))
            ->create(new PreparedPassword('user'), new PreparedPassword('owner'), new Revision5Permissions(-4), new EncryptMetadata(true));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('Extensions'), new DictionaryType(new Entry(new NameType('ADBE'), new DictionaryType(
            new Entry(new NameType('BaseVersion'), new NameType('1.7')),
            new Entry(new NameType('ExtensionLevel'), new IntegerType(3)),
        )))));
        $id = new HexadecimalString(str_repeat('i', 16));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('revision 5 is prohibited');
        (new FileWriter(encryption: $encryption))->serialize(new FileStructure(new FileHeader(PdfVersion::PDF20), new FileBody($catalog), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id, $id)))));
    }
}
