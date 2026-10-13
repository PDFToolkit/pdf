<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\Operators\InlineImage;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityLimitsTest extends TestCase
{
    public function testInlineImageRejectsOverflowInRowSize(): void
    {
        $dictionary = $this->unfilteredImageDictionary(PHP_INT_MAX, 1);

        $this->expectException(\OverflowException::class);
        new InlineImage($dictionary, '');
    }

    public function testInlineImageRejectsOverflowInTotalSize(): void
    {
        $dictionary = $this->unfilteredImageDictionary(1, PHP_INT_MAX);

        $this->expectException(\OverflowException::class);
        new InlineImage($dictionary, '');
    }

    /** @return iterable<string, array{CrossReferenceFormat, int}> */
    public static function identifierOverflowCases(): iterable
    {
        yield 'table needs the trailer Size' => [CrossReferenceFormat::Table, PHP_INT_MAX];
        yield 'stream needs an additional xref object and Size' => [CrossReferenceFormat::Stream, PHP_INT_MAX - 1];
    }

    #[DataProvider('identifierOverflowCases')]
    public function testWriterRejectsIdentifiersThatLeaveNoRoomForManagedNumbers(CrossReferenceFormat $format, int $number): void
    {
        $file = $this->fileWithObjectNumber($number, $format);

        $this->expectException(\OverflowException::class);
        $file->toPdf();
    }

    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function crossReferenceFormats(): iterable
    {
        yield 'table' => [CrossReferenceFormat::Table];
        yield 'stream' => [CrossReferenceFormat::Stream];
    }

    #[DataProvider('crossReferenceFormats')]
    public function testEnormousObjectNumberGapsAreRejectedBeforeFreeEntryAllocation(CrossReferenceFormat $format): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fileWithObjectNumber(1_000_000_000, $format)->toPdf();
    }

    #[DataProvider('crossReferenceFormats')]
    public function testNonOverflowingButUnboundedIndexIsRejected(CrossReferenceFormat $format): void
    {
        $number = PHP_INT_MAX - ($format === CrossReferenceFormat::Stream ? 2 : 1);
        $this->expectException(\InvalidArgumentException::class);
        $this->fileWithObjectNumber($number, $format)->toPdf();
    }

    public function testSharedDirectSubgraphsAreValidatedOnce(): void
    {
        $leaf = new class (new Entry(new NameType('Leaf'), new IntegerType(0))) extends DictionaryType {
            public int $entryVisits = 0;

            public function entries(): iterable
            {
                ++$this->entryVisits;
                yield from parent::entries();
            }
        };
        $shared = $leaf;
        for ($depth = 0; $depth < 32; ++$depth) {
            $shared = new ArrayType($shared, $shared);
        }
        $dictionary = new DictionaryType(
            new Entry(new NameType('W'), new IntegerType(1)),
            new Entry(new NameType('H'), new IntegerType(1)),
            new Entry(new NameType('BPC'), new IntegerType(1)),
            new Entry(new NameType('IM'), new BooleanType(true)),
            new Entry(new NameType('CustomShared'), $shared),
        );

        new InlineImage($dictionary, "\0");

        self::assertSame(1, $leaf->entryVisits);
    }

    public function testAnActiveDirectCycleIsStillRejected(): void
    {
        $cyclic = new class () extends DictionaryType {
            public DictionaryType $child;

            public function entries(): iterable
            {
                yield new Entry(new NameType('Cycle'), $this->child);
            }
        };
        $cyclic->child = $cyclic;
        $dictionary = new DictionaryType(
            new Entry(new NameType('W'), new IntegerType(1)),
            new Entry(new NameType('H'), new IntegerType(1)),
            new Entry(new NameType('BPC'), new IntegerType(1)),
            new Entry(new NameType('IM'), new BooleanType(true)),
            new Entry(new NameType('CustomCycle'), $cyclic),
        );

        $this->expectException(\InvalidArgumentException::class);
        new InlineImage($dictionary, "\0");
    }

    private function unfilteredImageDictionary(int $width, int $height): DictionaryType
    {
        return new DictionaryType(
            new Entry(new NameType('W'), new IntegerType($width)),
            new Entry(new NameType('H'), new IntegerType($height)),
            new Entry(new NameType('BPC'), new IntegerType(16)),
            new Entry(new NameType('CS'), new NameType('G')),
        );
    }

    private function fileWithObjectNumber(int $number, CrossReferenceFormat $format): FileStructure
    {
        return new FileStructure(
            new FileHeader(),
            new FileBody(
                new DocumentCatalog(new PageTreeNode()),
                new IndirectObject(new IntegerType(42), new ObjectIdentifier(new ObjectNumber($number))),
            ),
            crossReferenceFormat: $format,
        );
    }
}
