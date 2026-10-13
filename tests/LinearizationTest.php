<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Destinations\Fit;
use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Serialization\LinearizedWriter;
use PDF\Security\EncryptMetadata;
use PDF\Security\FileIdentifier;
use PDF\Security\HistoricalDataCipher;
use PDF\Security\HistoricalKeyLength;
use PDF\Security\HistoricalPermissions;
use PDF\Security\HistoricalSecurityRevision;
use PDF\Security\HistoricalStandardSecurityHandler;
use PDF\Security\OpenSslAes128Backend;
use PDF\Security\PdfDocEncodedPassword;
use PDF\Syntax\DocumentStructure\CatalogDictionary\OpenAction;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Linearization\ExtendedGenericHintTable;
use PDF\Syntax\Linearization\EmbeddedFileHintGroup;
use PDF\Syntax\Linearization\LinearizationPlan;
use PDF\Syntax\Linearization\ThumbnailGraphNormalization;
use PDF\Syntax\Linearization\GenericHintTable;
use PDF\Syntax\Linearization\HintBitStream;
use PDF\Syntax\Linearization\HintBitWidth;
use PDF\Syntax\Linearization\HintField;
use PDF\Syntax\Linearization\HintStreamExtent;
use PDF\Syntax\Linearization\HintStreamSplit;
use PDF\Syntax\Linearization\LinearizationLimits;
use PDF\Syntax\Linearization\PrimaryHintStream;
use PDF\Syntax\Linearization\PageHint;
use PDF\Syntax\Linearization\PageOffsetHintTable;
use PDF\Syntax\Linearization\SharedObjectGroup;
use PDF\Syntax\Linearization\SharedObjectHintTable;
use PDF\Syntax\Linearization\SharedObjectReference;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinearizationTest extends TestCase
{
    public function testHintItemVectorsMatchAnIndependentQpdf1242Fixture(): void
    {
        $refs = [];
        foreach ([2, 3, 4, 5] as $id) {
            $refs[] = new SharedObjectReference(new IntegerType($id));
        }
        $table = new PageOffsetHintTable(
            new PageHint(new IntegerType(6), new ByteOffset(622), new IntegerType(802), new IntegerType(0), new IntegerType(802)),
            new PageHint(new IntegerType(2), new ByteOffset(1424), new IntegerType(525), new IntegerType(0), new IntegerType(525), ...$refs),
            new PageHint(new IntegerType(2), new ByteOffset(1949), new IntegerType(565), new IntegerType(0), new IntegerType(565), ...$refs),
        );
        // Decompressed /S=51 primary hint bytes from an independently generated
        // qpdf 12.4.2 linearized three-page fixture, including vector alignment.
        self::assertSame('000000020000026e00030000020d00090000000000000000020d0009000300030000000480008a80050012004e54e58a800500', bin2hex($table->bytes()));
        $groups = [];
        $offset = 622;
        foreach ([100, 461, 56, 71, 76, 38] as $index => $length) {
            $groups[] = new SharedObjectGroup(new ObjectNumber(10 + $index), new ByteOffset($offset), new IntegerType(1), new IntegerType($length));
            $offset += $length;
        }
        $shared = new SharedObjectHintTable(new IntegerType(6), $groups[0], ...array_slice($groups, 1));
        self::assertSame('0000000000000000000000060000000600000000002600091f69c24211300000', bin2hex($shared->bytes()));
    }

    public function testGenericAndExtendedGenericHintsAreUnsignedBigEndian(): void
    {
        $generic = new GenericHintTable(new ObjectNumber(5), new ByteOffset(400), new IntegerType(2), new IntegerType(88));
        self::assertSame('00000005000001900000000200000058', bin2hex($generic->bytes()));
        $extended = new ExtendedGenericHintTable($generic, new SharedObjectReference(new IntegerType(1)), new SharedObjectReference(new IntegerType(5)));
        self::assertSame('0000000500000190000000020000005800000002000334', bin2hex($extended->bytes()));
    }

    public function testBitFieldsPackAcrossBytesAndDoNotMutateOnInspection(): void
    {
        $stream = new HintBitStream();
        $stream->write(new HintField(new IntegerType(5), new IntegerType(3)), new HintField(new IntegerType(31), new IntegerType(5)), new HintField(new IntegerType(1), new IntegerType(1)));
        self::assertSame('bf80', bin2hex($stream->bytes()));
        $continued = $stream->write(new HintField(new IntegerType(0), new IntegerType(7)));
        self::assertSame('bf80', bin2hex($continued->align()->bytes()));
        self::assertSame(0, (new HintBitWidth(new IntegerType(0)))->bits->value);
        self::assertSame(32, (new HintBitWidth(new IntegerType(4_294_967_295)))->bits->value);
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidBitFields(): iterable
    {
        yield 'negative' => [-1, 8];
        yield 'negative width' => [0, -1];
        yield 'too wide' => [0, 33];
        yield 'nonzero zero-width' => [1, 0];
        yield 'overflow' => [256, 8];
        yield 'uint32 overflow' => [4_294_967_296, 32];
    }

    #[DataProvider('invalidBitFields')]
    public function testInvalidHintFieldsAreRejected(int $value, int $bits): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HintField(new IntegerType($value), new IntegerType($bits));
    }

    public function testEveryOffsetPointsToItsActualObjectAndFirstTrailerLinksForward(): void
    {
        [$file] = $this->document();
        $bytes = (new LinearizedWriter())->serialize($file);
        self::assertMatchesRegularExpression('/^%PDF-1\.7\n%[\x80-\xff]{4}\n\d+ 0 obj\n<< \/Linearized 1 /', $bytes);
        if (preg_match('/\/L (\d+).*?\/H \[(\d+) (\d+)\].*?\/O (\d+).*?\/E (\d+).*?\/N (\d+).*?\/T (\d+)/', $bytes, $parameters) !== 1) {
            self::fail('The file must declare its linearization parameters.');
        }
        self::assertSame(strlen($bytes), (int) $parameters[1]);
        self::assertSame(3, (int) $parameters[6]);
        self::assertLessThan(1024, strpos($bytes, 'endobj'));
        self::assertMatchesRegularExpression('/^\d+ 0 obj\n<< \/S \d+ \/Length \d+ >>\nstream\n/', substr($bytes, (int) $parameters[2], (int) $parameters[3]));
        self::assertStringStartsWith('1 0 obj', substr($bytes, (int) $parameters[5]));
        self::assertSame("\n0000000000 65535 f \n", substr($bytes, (int) $parameters[7], 21));
        preg_match_all('/xref\n(\d+) (\d+)\n((?:\d{10} \d{5} [nf] \n)+)/', $bytes, $sections, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        self::assertCount(2, $sections);
        self::assertGreaterThan(0, (int) $sections[0][1][0]);
        self::assertSame(0, (int) $sections[1][1][0]);
        foreach ($sections as $section) {
            $first = (int) $section[1][0];
            foreach (explode("\n", trim($section[3][0])) as $index => $record) {
                if (str_contains($record, ' n')) {
                    self::assertStringStartsWith(($first + $index) . ' 0 obj', substr($bytes, (int) substr($record, 0, 10)));
                }
            }
        }
        if (preg_match('/\/Prev (\d+)/', $bytes, $prev) !== 1) {
            self::fail('The first trailer must point forward to the main xref.');
        }
        self::assertSame($sections[1][0][1], (int) $prev[1]);
        if (preg_match('/startxref\n(\d+)\n%%EOF\n$/', $bytes, $startxref) !== 1) {
            self::fail('The final startxref must point to the first-page xref.');
        }
        self::assertSame($sections[0][0][1], (int) $startxref[1]);
    }

    public function testNormalizationAndRenumberingPreserveTheOriginalGraphAndExplicitNumbers(): void
    {
        [$file, $pages, $leaves] = $this->document();
        $catalog = $file->root?->target();
        self::assertNotNull($catalog);
        $explicit = new ObjectIdentifier(new ObjectNumber(900));
        $file = new FileStructure($file->header, new FileBody(new IndirectObject($catalog, $explicit)), new IndirectReference($catalog, $explicit));
        $before = $file->toPdf();
        $first = (new LinearizedWriter())->serialize($file);
        $second = (new LinearizedWriter())->serialize($file);
        self::assertSame($first, $second);
        self::assertSame($before, $file->toPdf());
        self::assertSame($pages, $leaves[0]->parent());
        self::assertNull($leaves[0]->get('MediaBox'));
        self::assertNotNull($pages->get('MediaBox'));
        self::assertStringContainsString('900 0 obj', $before);
        self::assertStringNotContainsString('900 0 obj', $first);
    }

    public function testOpenActionSelectsAnExistingArbitraryFirstPage(): void
    {
        [$file, $pages, $leaves] = $this->document();
        $catalog = new DocumentCatalog($pages, new OpenAction(new Fit($leaves[2])));
        $file = new FileStructure($file->header, new FileBody($catalog), new IndirectReference($catalog));
        $bytes = (new LinearizedWriter())->serialize($file);
        self::assertMatchesRegularExpression('/\/P 0000000002 >>/', $bytes);
        if (preg_match('/\/O (\d+)/', $bytes, $number) !== 1) {
            self::fail('The initial page must have an object number.');
        }
        preg_match('/' . (int) $number[1] . ' 0 obj\n(.*?)\nendobj/s', $bytes, $page);
        self::assertStringContainsString('/Type /Page', $page[1]);
    }

    public function testObjectCapacityFailsBeforeConstructingLargeReferenceTables(): void
    {
        [$file] = $this->document();
        $this->expectException(\LengthException::class);
        (new LinearizedWriter(new LinearizationLimits(maximumObjects: 4)))->serialize($file);
    }

    public function testFileCapacityIsExplicit(): void
    {
        [$file] = $this->document();
        $this->expectException(\LengthException::class);
        (new LinearizedWriter(new LinearizationLimits(maximumFileBytes: 1024)))->serialize($file);
    }

    public function testCrossReferenceStreamLayoutHasTwoDistinctRootAndMinimalMainSections(): void
    {
        [$file] = $this->document();
        $file = new FileStructure($file->header, $file->body, $file->root, null, CrossReferenceFormat::Stream);
        $bytes = (new LinearizedWriter())->serialize($file);
        self::assertSame(2, substr_count($bytes, '/Type /XRef'));
        self::assertSame(1, substr_count($bytes, '/Root '));
        self::assertSame(1, substr_count($bytes, '/Prev '));
        self::assertStringNotContainsString("\nxref\n", $bytes);
    }

    public function testExistingMembersAreRepackedWithNewIdentifiersAndProtectedPageObjectsAreExpanded(): void
    {
        [$file, $pages, $leaves] = $this->document();
        $catalog = $file->root?->target();
        self::assertNotNull($catalog);
        $resources = $leaves[0]->effectiveResources();
        $resources = $resources instanceof IndirectReference ? $resources->target() : $resources;
        self::assertInstanceOf(ResourceDictionary::class, $resources);
        $font = $resources->get('Font');
        self::assertInstanceOf(DictionaryType::class, $font);
        $reference = $font->get('F1');
        self::assertInstanceOf(IndirectReference::class, $reference);
        $container = new ObjectStream(
            new ObjectStreamEntry(new ObjectNumber(100), $catalog),
            new ObjectStreamEntry(new ObjectNumber(101), $pages),
            new ObjectStreamEntry(new ObjectNumber(102), $leaves[0]),
            new ObjectStreamEntry(new ObjectNumber(103), $reference->target()),
            new FlateDecode(),
        );
        $file = new FileStructure($file->header, new FileBody($catalog, $container), $file->root, null, CrossReferenceFormat::Stream);
        $before = $file->toPdf();
        $bytes = (new LinearizedWriter())->serialize($file);
        self::assertSame($before, $file->toPdf());
        self::assertSame(2, substr_count($bytes, '/Type /ObjStm'));
        self::assertSame(1, substr_count($bytes, '/Type /Catalog'));
        self::assertSame(3, substr_count($bytes, '/Type /Page '));
        self::assertStringNotContainsString('100 0 obj', $bytes);
        self::assertStringContainsString('/N 1 /First ', $bytes);
    }

    public function testOverflowContinuationMaySplitAHeaderWithoutChangingAnyDecodedHint(): void
    {
        $stream = new PrimaryHintStream(
            new PageOffsetHintTable(new PageHint(new IntegerType(1), new ByteOffset(400), new IntegerType(100), new IntegerType(0), new IntegerType(0))),
            new SharedObjectHintTable(new IntegerType(1), new SharedObjectGroup(new ObjectNumber(5), new ByteOffset(400), new IntegerType(1), new IntegerType(100))),
        );
        self::assertIsString($stream->data);
        foreach ([1, 7, 36, strlen($stream->data) - 1] as $boundary) {
            [$primary, $overflow] = (new HintStreamSplit(new IntegerType($boundary)))->split($stream);
            self::assertSame($stream->data, $primary->data . $overflow->data);
            self::assertSame($stream->dictionary(), $primary->dictionary());
            self::assertCount(0, $overflow->dictionary());
        }
    }

    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function xrefFormats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('xrefFormats')]
    public function testOverflowExtentsAreWholeObjectsAndTheirNumbersBelongToTheFirstSection(CrossReferenceFormat $format): void
    {
        [$file] = $this->document();
        $file = new FileStructure($file->header, $file->body, $file->root, null, $format);
        $bytes = (new LinearizedWriter(hintSplit: new HintStreamSplit(new IntegerType(1))))->serialize($file);
        if (preg_match('/\/H \[(\d+) (\d+) (\d+) (\d+)\]/', $bytes, $h) !== 1) {
            self::fail('Both whole-object extents must be declared in H.');
        }
        $primary = substr($bytes, (int) $h[1], (int) $h[2]);
        $overflow = substr($bytes, (int) $h[3], (int) $h[4]);
        self::assertMatchesRegularExpression('/^\d+ 0 obj\n<< \/S \d+ \/Length 1 >>\nstream\n.\nendstream\nendobj\n$/s', $primary);
        self::assertMatchesRegularExpression('/^\d+ 0 obj\n<< \/Length \d+ >>\nstream\n.+\nendstream\nendobj\n$/s', $overflow);
        self::assertSame((int) $primary + 1, (int) $overflow);
        if (preg_match('/\/L (\d+).*?\/E (\d+).*?\/T (\d+)/', $bytes, $layout) !== 1) {
            self::fail('The complete linearization layout must be declared.');
        }
        self::assertSame(strlen($bytes), (int) $layout[1]);
        self::assertLessThan((int) $h[3], (int) $layout[2]);
        if (preg_match('/\/Prev (\d+)/', $bytes, $previous) !== 1) {
            self::fail('The first section must point to the main section after the overflow.');
        }
        if ($format === CrossReferenceFormat::Hybrid) {
            if (preg_match('/\/XRefStm (\d+)/', $bytes, $auxiliary) !== 1) {
                self::fail('A hybrid first-page trailer must locate the auxiliary stream.');
            }
            self::assertSame((int) $h[3] + (int) $h[4], (int) $auxiliary[1]);
            self::assertGreaterThan((int) $auxiliary[1], (int) $previous[1]);
        } else {
            self::assertSame((int) $h[3] + (int) $h[4], (int) $previous[1]);
        }
        self::assertLessThan(1024, strpos($bytes, 'endobj'));
    }

    public function testOverflowSelectionRejectsAnEmptyContinuation(): void
    {
        [$file] = $this->document();
        $this->expectException(\LengthException::class);
        (new LinearizedWriter(hintSplit: new HintStreamSplit(new IntegerType(4096))))->serialize($file);
    }

    public function testOverflowExtentRejectsAddressSpaceOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HintStreamExtent(new ByteOffset(4_294_967_295), new IntegerType(1));
    }

    #[DataProvider('xrefFormats')]
    public function testEncryptedHintContinuationUsesItsOwnObjectIdentifier(CrossReferenceFormat $format): void
    {
        [$file] = $this->document();
        $id = new FileIdentifier(new HexadecimalString(str_repeat('i', 16)));
        $encryption = (new HistoricalStandardSecurityHandler(aes: new OpenSslAes128Backend()))->create(
            HistoricalSecurityRevision::Revision4,
            new PdfDocEncodedPassword(new HexadecimalString('user')),
            new PdfDocEncodedPassword(new HexadecimalString('owner')),
            new HistoricalPermissions(new IntegerType(-4), HistoricalSecurityRevision::Revision4),
            $id,
            new HistoricalKeyLength(new IntegerType(128)),
            HistoricalDataCipher::Aes128,
            new EncryptMetadata(false),
        );
        $file = new FileStructure($file->header, $file->body, $file->root, new DictionaryType(new Entry(new NameType('ID'), new ArrayType($id->value, $id->value))), $format);
        $bytes = (new LinearizedWriter(hintSplit: new HintStreamSplit(new IntegerType(1)), encryption: $encryption))->serialize($file);
        if (preg_match('/\/H \[(\d+) (\d+) (\d+) (\d+)\]/', $bytes, $h) !== 1) {
            self::fail('Encrypted hint extents must describe both complete cipher streams.');
        }
        $decoded = '';
        foreach ([[1, 2], [3, 4]] as [$offset, $length]) {
            $object = substr($bytes, (int) $h[$offset], (int) $h[$length]);
            if (preg_match('/^(\d+) 0 obj\n<< .*?\/Length (\d+) >>\nstream\n/s', $object, $stream) !== 1) {
                self::fail('The actual encrypted stream must declare its encoded Length.');
            }
            $ciphertext = substr($object, strlen($stream[0]), (int) $stream[2]);
            $decoded .= $encryption->decrypt($ciphertext, new ObjectIdentifier(new ObjectNumber((int) $stream[1])));
        }
        self::assertStringStartsWith("\0\0\0\2", $decoded);
        self::assertGreaterThan(36, strlen($decoded));
        self::assertStringNotContainsString('(Page 0)', $bytes);
        self::assertStringContainsString('/Encrypt ', $bytes);
        self::assertStringContainsString('/CFM /AESV2', $bytes);
    }

    /** @return array{FileStructure, PageTreeNode, list<PageObject>} */
    private function document(): array
    {
        $font = new Type1Font(StandardFont::Helvetica);
        $resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
        $pages = new PageTreeNode(
            new Entry(new NameType('MediaBox'), new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842))),
            new Entry(new NameType('Resources'), new IndirectReference($resources)),
        );
        $leaves = [];
        for ($index = 0; $index < 3; ++$index) {
            $page = new PageObject(null, null, new StreamObject('BT /F1 12 Tf 50 750 Td (Page ' . $index . ') Tj ET'));
            $pages->addKid($page);
            $leaves[] = $page;
        }
        $catalog = new DocumentCatalog($pages);
        return [new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)), $pages, $leaves];
    }

    public function testReusedMixedThumbnailsRequireExplicitNormalization(): void
    {
        [$file] = $this->thumbnailDocument();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit ThumbnailGraphNormalization');
        (new LinearizedWriter())->serialize($file);
    }

    #[DataProvider('xrefFormats')]
    public function testThumbnailNormalizationCopiesOnlyTheOutputGraphAndKeepsManagedStreamEntries(CrossReferenceFormat $format): void
    {
        [$file, $thumbnail, $colour] = $this->thumbnailDocument();
        $file = new FileStructure($file->header, $file->body, $file->root, null, $format);
        $before = $file->toPdf();
        $policy = new ThumbnailGraphNormalization();
        $plan = new LinearizationPlan($file, thumbnailNormalization: $policy);
        self::assertCount(2, $plan->thumbnailObjects);
        self::assertCount(1, $plan->thumbnailSharedObjects);
        self::assertNotSame($colour, $plan->thumbnailSharedObjects[0]);
        self::assertSame($thumbnail, $plan->thumbnailObjects[0][0]);
        self::assertNotSame($thumbnail, $plan->thumbnailObjects[2][0]);
        $bytes = (new LinearizedWriter(thumbnailNormalization: $policy))->serialize($file);
        self::assertSame($before, $file->toPdf());
        self::assertSame(2, substr_count($bytes, '/Subtype /Image'));
        self::assertMatchesRegularExpression('/\/BitsPerComponent 8 \/Length 11 \/Filter \/FlateDecode/', $bytes);
    }

    public function testSharedEmbeddedFilesUseOnlySharedReferencesAndPreserveTheSourceGraph(): void
    {
        [$base] = $this->document();
        $catalog = $base->root?->target();
        self::assertInstanceOf(DictionaryType::class, $catalog);
        $dependency = new DictionaryType(new Entry(new NameType('PrivateLabel'), new HexadecimalString('shared embedded-file dictionary')));
        $first = new StreamObject('First payload', new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile')), new Entry(new NameType('Params'), new IndirectReference($dependency))));
        $second = new StreamObject('Second payload', new DictionaryType(new Entry(new NameType('Type'), new NameType('EmbeddedFile')), new Entry(new NameType('Params'), new IndirectReference($dependency))));
        $catalog = $catalog->with(new Entry(new NameType('PrivateFiles'), new ArrayType(new IndirectReference($first), new IndirectReference($second))));
        $file = new FileStructure($base->header, new FileBody($catalog), new IndirectReference($catalog));
        $before = $file->toPdf();
        $plan = new LinearizationPlan($file);
        self::assertSame([[], []], $plan->embeddedFileObjects);
        self::assertCount(3, $plan->sharedObjects);
        $bytes = (new LinearizedWriter())->serialize($file);
        self::assertSame($before, $file->toPdf());
        self::assertSame(2, substr_count($bytes, '/Type /EmbeddedFile'));
        self::assertSame(1, substr_count($bytes, strtoupper(bin2hex('shared embedded-file dictionary'))));
        self::assertStringContainsString('/B ', $bytes);
    }

    public function testEmbeddedFileHintsRejectMixedPrivateAndSharedForms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmbeddedFileHintGroup(new ObjectNumber(5), new IntegerType(1), new IntegerType(100), new SharedObjectReference(new IntegerType(0)));
    }

    /** @return array{FileStructure, StreamObject, ArrayType} */
    private function thumbnailDocument(): array
    {
        $colour = new ArrayType(new NameType('CalRGB'), new DictionaryType(new Entry(new NameType('WhitePoint'), new ArrayType(new IntegerType(1), new IntegerType(1), new IntegerType(1)))));
        $thumbnail = new StreamObject("\xff\0\0", new DictionaryType(
            new Entry(new NameType('Type'), new NameType('XObject')),
            new Entry(new NameType('Subtype'), new NameType('Image')),
            new Entry(new NameType('Width'), new IntegerType(1)),
            new Entry(new NameType('Height'), new IntegerType(1)),
            new Entry(new NameType('ColorSpace'), new IndirectReference($colour)),
            new Entry(new NameType('BitsPerComponent'), new IntegerType(8)),
        ), new FlateDecode());
        $resources = new ResourceDictionary(new Entry(new NameType('ColorSpace'), new DictionaryType(new Entry(new NameType('CS'), new IndirectReference($colour)))));
        $pages = new PageTreeNode(new Entry(new NameType('MediaBox'), new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100))), new Entry(new NameType('Resources'), new IndirectReference($resources)));
        for ($index = 0; $index < 3; ++$index) {
            $entries = $index === 1 ? [] : [new Entry(new NameType('Thumb'), new IndirectReference($thumbnail))];
            $pages->addKid(new PageObject(null, null, new StreamObject('q Q'), ...$entries));
        }
        $catalog = new DocumentCatalog($pages);
        return [new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)), $thumbnail, $colour];
    }
}
