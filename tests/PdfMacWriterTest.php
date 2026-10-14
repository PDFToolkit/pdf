<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Extensions\IsoExtensions;
use PDF\Document\Extensions\IsoTechnicalSpecification;
use PDF\FileStructure;
use PDF\Security\EncryptMetadata;
use PDF\Security\ModernFileEncryption;
use PDF\Security\ModernStandardSecurityHandler;
use PDF\Security\OpenSslAes256Backend;
use PDF\Security\Pdf20Permissions;
use PDF\Security\PdfMacTokenComposer;
use PDF\Security\PreparedPassword;
use PDF\Security\SystemRandomSource;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Document\ResourceDictionary;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfMacWriterTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testEveryCrossReferenceFormatAuthenticatesItsActualFinalBytes(CrossReferenceFormat $format): void
    {
        [$file, $encryption, $composer] = $this->fixture($format);
        $revision = (new FileWriter(encryption: $encryption, mac: $composer))->revision($file);
        $this->verifyRevision($revision, $composer);
        self::assertSame(strlen($revision->bytes), strlen((string) $revision));
        foreach ($revision->objects() as $record) {
            if ($record->dictionary?->has('AuthCode')) {
                self::assertSame(
                    $revision->trailerEntries->get('AuthCode')->toPdf(new \PDF\Serialization\SerializationContext()),
                    $record->dictionary->get('AuthCode')->toPdf(new \PDF\Serialization\SerializationContext()),
                );
            }
        }
    }

    public function testRequiredMacCannotBeOmitted(): void
    {
        [$file, $encryption] = $this->fixture(CrossReferenceFormat::Table);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('bit13');
        (new FileWriter(encryption: $encryption))->revision($file);
    }

    #[DataProvider('formats')]
    public function testIncrementalRevisionsReauthenticateTheWholeFileAndKeepEarlierBytes(CrossReferenceFormat $format): void
    {
        [$file, $encryption, $composer] = $this->fixture($format);
        $base = (new FileWriter(encryption: $encryption, mac: $composer))->revision($file);
        $nextComposer = new PdfMacTokenComposer($encryption->key, str_repeat('s', 32), new SystemRandomSource());
        $next = (new IncrementalWriter(mac: $nextComposer))->serialize(new IncrementalUpdate($base, new FileBody()));
        self::assertStringStartsWith($base->bytes, $next->bytes);
        $this->verifyRevision($base, $composer);
        $this->verifyRevision($next, $nextComposer);
        self::assertNotSame($base->trailerEntries->get('AuthCode')->get('MAC')->value, $next->trailerEntries->get('AuthCode')->get('MAC')->value);
    }

    public function testAnIncrementalUpdateCannotCarryTheOldMacForward(): void
    {
        [$file, $encryption, $composer] = $this->fixture(CrossReferenceFormat::Table);
        $base = (new FileWriter(encryption: $encryption, mac: $composer))->revision($file);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stale PDF MAC');
        (new IncrementalUpdate($base, new FileBody()))->toRevision();
    }

    #[DataProvider('formats')]
    public function testLinearizedFilesAuthenticateTheCompleteFinalLayout(CrossReferenceFormat $format): void
    {
        [$file, $encryption, $composer] = $this->fixture($format);
        $base = (new LinearizedWriter(encryption: $encryption, mac: $composer))->revision($file);
        $this->verifyRevision($base, $composer);
        $nextComposer = new PdfMacTokenComposer($encryption->key, str_repeat('s', 32), new SystemRandomSource());
        $next = (new IncrementalWriter(mac: $nextComposer))->serialize(new IncrementalUpdate($base, new FileBody()));
        self::assertStringStartsWith($base->bytes, $next->bytes);
        $this->verifyRevision($next, $nextComposer);
    }

    public function testComposerMustUseTheActualEncryptionKeyAndSalt(): void
    {
        [$file, $encryption] = $this->fixture(CrossReferenceFormat::Table);
        $other = new PdfMacTokenComposer(new \PDF\Security\FileEncryptionKey(str_repeat('x', 32)), str_repeat('s', 32), new SystemRandomSource());
        $this->expectException(\LogicException::class);
        (new FileWriter(encryption: $encryption, mac: $other))->revision($file);
    }

    /** @return array{FileStructure, ModernFileEncryption, PdfMacTokenComposer} */
    private function fixture(CrossReferenceFormat $format): array
    {
        $random = new SystemRandomSource();
        $encryption = (new ModernStandardSecurityHandler(new OpenSslAes256Backend(), $random))->create(
            new PreparedPassword('user'),
            new PreparedPassword('owner'),
            new Pdf20Permissions(-4100, requireMac: true),
            new EncryptMetadata(true),
            kdfSalt: str_repeat('s', 32),
        );
        $composer = new PdfMacTokenComposer($encryption->key, str_repeat('s', 32), $random);
        $page = new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(200), new NumericType(200)), new ResourceDictionary());
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), new Entry(new NameType('Extensions'), new IsoExtensions(IsoTechnicalSpecification::IntegrityProtection)));
        $ids = new ArrayType(new HexadecimalString(str_repeat('i', 16)), new HexadecimalString(str_repeat('i', 16)));
        $file = new FileStructure(new FileHeader('2.0'), new FileBody($catalog), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), $ids)), crossReferenceFormat: $format);
        return [$file, $encryption, $composer];
    }

    private function verifyRevision(FileRevision $revision, PdfMacTokenComposer $composer): void
    {
        $auth = $revision->trailerEntries->get('AuthCode');
        self::assertInstanceOf(DictionaryType::class, $auth);
        $range = $auth->get('ByteRange');
        $mac = $auth->get('MAC');
        self::assertInstanceOf(ArrayType::class, $range);
        self::assertInstanceOf(StringType::class, $mac);
        [$zero, $before, $after, $length] = array_map(static fn ($part): int => $part->integer(), $range->items);
        self::assertSame(0, $zero);
        self::assertSame(strlen($revision->bytes), $after + $length);
        self::assertSame('<' . strtoupper(bin2hex($mac->value)) . '>', substr($revision->bytes, $before, $after - $before));
        self::assertSame($composer->tokenLength(), strlen($mac->value));
        $covered = substr($revision->bytes, 0, $before) . substr($revision->bytes, $after, $length);
        $composer->verify($mac->value, $covered);
        try {
            $composer->verify($mac->value, $covered . 'tamper');
            self::fail('A changed covered byte sequence must invalidate the PDF MAC.');
        } catch (\UnexpectedValueException $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }
}
