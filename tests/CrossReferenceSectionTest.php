<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceLimits;
use PDF\Syntax\FileStructure\CrossReferenceSection;
use PDF\Syntax\FileStructure\CrossReferenceStreamCompressedEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamFreeEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamInUseEntry;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IntegerType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CrossReferenceSectionTest extends TestCase
{
    public function testInitialSectionContainsEveryNumberAndLinksAllHoles(): void
    {
        $second = new CrossReferenceStreamInUseEntry(new ObjectNumber(2), new ByteOffset(16));
        $fifth = new CrossReferenceStreamInUseEntry(new ObjectNumber(5), new ByteOffset(80));
        $section = new CrossReferenceSection(new IntegerType(7), entries: $fifth, second: $second);
        $entries = $section->entries();
        self::assertSame(range(0, 6), array_map(static fn ($entry): int => $entry->number(), $entries));
        self::assertSame([0, 1, 65535], $entries[0]->fields());
        self::assertSame([0, 3, 0], $entries[1]->fields());
        self::assertSame($second, $entries[2]);
        self::assertSame([0, 4, 0], $entries[3]->fields());
        self::assertSame([0, 6, 0], $entries[4]->fields());
        self::assertSame($fifth, $entries[5]);
        self::assertSame([0, 0, 0], $entries[6]->fields());
        self::assertSame(1, $section->freeListHead()->value);
    }

    public function testIncrementalSectionOmitsUnchangedNumbersAndExtendsThePreviousFreeList(): void
    {
        $replacement = new CrossReferenceStreamInUseEntry(new ObjectNumber(2), new ByteOffset(1000), new GenerationNumber(3));
        $created = new CrossReferenceStreamCompressedEntry(new ObjectNumber(7), new ObjectNumber(8), new IntegerType(0));
        $container = new CrossReferenceStreamInUseEntry(new ObjectNumber(8), new ByteOffset(1050));
        $section = new CrossReferenceSection(new IntegerType(10), new IntegerType(6), new IntegerType(3), new CrossReferenceLimits(), $replacement, $created, $container);
        $entries = $section->entries();
        self::assertSame([0, 2, 6, 7, 8, 9], array_map(static fn ($entry): int => $entry->number(), $entries));
        self::assertSame([0, 6, 65535], $entries[0]->fields());
        self::assertSame($replacement, $entries[1]);
        self::assertSame([0, 9, 0], $entries[2]->fields());
        self::assertSame($created, $entries[3]);
        self::assertSame($container, $entries[4]);
        self::assertSame([0, 3, 0], $entries[5]->fields());
        self::assertSame(6, $section->freeListHead()->value);
    }

    public function testUpdateWithoutNewNumbersPreservesThePreviousFreeListHead(): void
    {
        $section = new CrossReferenceSection(new IntegerType(8), new IntegerType(8), new IntegerType(4));
        self::assertCount(1, $section->entries());
        self::assertSame([0, 4, 65535], $section->entries()[0]->fields());
        self::assertSame(4, $section->freeListHead()->value);
    }

    public function testConfiguredCapacityAllowsAnExplicitLargerSection(): void
    {
        $section = new CrossReferenceSection(new IntegerType(100001), new IntegerType(100001), new IntegerType(0), new CrossReferenceLimits(new IntegerType(100001)));
        self::assertSame(100001, $section->size->value);
        self::assertCount(1, $section->entries());
    }

    public function testHugeSparseSizeIsRejectedBeforeAllocatingFreeEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType(PHP_INT_MAX));
    }

    #[DataProvider('invalidBounds')]
    public function testInvalidBoundsAreRejected(int $size, int $lower, int $head): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType($size), new IntegerType($lower), new IntegerType($head));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidBounds(): iterable
    {
        yield 'empty size' => [0, 1, 0];
        yield 'negative size' => [-1, 1, 0];
        yield 'zero lower bound' => [5, 0, 0];
        yield 'lower bound above size' => [5, 6, 0];
        yield 'negative head' => [5, 3, -1];
        yield 'head must be previous' => [5, 3, 3];
        yield 'initial head must be zero' => [5, 1, 1];
    }

    #[DataProvider('invalidLimits')]
    public function testEntryCapacityMustBePositive(int $maximum): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceLimits(new IntegerType($maximum));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testSectionCannotReplaceItsManagedFreeEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType(3), entries: new CrossReferenceStreamFreeEntry(new IntegerType(1), new IntegerType(0)));
    }

    public function testDuplicateLiveNumbersAreRejected(): void
    {
        $entry = new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16));
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType(3), entries: $entry, duplicate: $entry);
    }

    public function testLiveNumberCannotReachSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType(3), entries: new CrossReferenceStreamInUseEntry(new ObjectNumber(3), new ByteOffset(16)));
    }

    public function testLiveObjectCannotUseThePermanentlyFreeGeneration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType(3), entries: new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16), new GenerationNumber(65535)));
    }

    public function testOldFreeListHeadCannotBecomeALiveReplacement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType(7), new IntegerType(6), new IntegerType(2), new CrossReferenceLimits(), new CrossReferenceStreamInUseEntry(new ObjectNumber(2), new ByteOffset(16)));
    }

    public function testCompressedContainerMustLieWithinSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceSection(new IntegerType(3), entries: new CrossReferenceStreamCompressedEntry(new ObjectNumber(1), new ObjectNumber(3), new IntegerType(0)));
    }
}
