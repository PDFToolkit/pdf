<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\ByteOffset;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\CrossReferenceStream;
use PDF\Syntax\FileStructure\CrossReferenceStreamCompressedEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamFreeEntry;
use PDF\Syntax\FileStructure\CrossReferenceStreamInUseEntry;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileTrailer;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

final class ObjectStreamsTest extends TestCase
{
    private static function root(): IndirectReference
    {
        return new IndirectReference(new DocumentCatalog(new PageTreeNode()));
    }

    public function testObjectStreamHeaderOffsetsAndFirstAreExactBytes(): void
    {
        $first = new LiteralString('one');
        $second = new ArrayType(new IntegerType(2), new NameType('X'));
        $stream = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(7), $first), new ObjectStreamEntry(new ObjectNumber(11), $second));
        $context = new SerializationContext();
        self::assertSame("7 0 11 6\n(one)\n[2 /X]\n", $stream->decodedBytes($context));
        self::assertSame(9, $stream->dictionaryFor($context)->get('First')->value);
        self::assertSame(2, $stream->dictionary()->get('N')->value);
        self::assertStringContainsString('/First 9 /Length 22', $stream->toPdf($context));
        self::assertSame('7 0', (string) $context->identifier($first));
        self::assertSame('11 0', (string) $context->identifier($second));
    }

    public function testEmbeddedReferencesResolveToReservedMemberNumbers(): void
    {
        $second = new LiteralString(str_repeat('x', 1200));
        $first = new DictionaryType(new Entry(new NameType('Target'), new IndirectReference($second)));
        $stream = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(20), $first), new ObjectStreamEntry(new ObjectNumber(21), $second));
        $context = new SerializationContext();
        $bytes = $stream->decodedBytes($context);
        self::assertStringContainsString('/Target 21 0 R', $bytes);
        [$header, $bodies] = explode("\n", $bytes, 2);
        $fields = array_map(intval(...), explode(' ', $header));
        self::assertSame([20, 0, 21], array_slice($fields, 0, 3));
        self::assertSame((string) $second . "\n", substr($bodies, $fields[3]));
        self::assertSame(strlen($header) + 1, $stream->dictionaryFor($context)->get('First')->value);
    }

    public function testObjectStreamCompressionPreservesTheDecodedPayload(): void
    {
        $plain = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(5), new LiteralString(str_repeat('abc', 100))));
        $compressed = $plain->withFilters(new FlateDecode());
        $context = new SerializationContext();
        self::assertSame($plain->decodedBytes($context), gzuncompress($compressed->bytes($context)));
        self::assertSame([], $plain->filters);
        self::assertStringContainsString('/Filter /FlateDecode', $compressed->toPdf($context));
    }

    public function testObjectStreamsRejectDuplicateObjectNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectStream(new ObjectStreamEntry(new ObjectNumber(5), new IntegerType(1)), new ObjectStreamEntry(new ObjectNumber(5), new IntegerType(2)));
    }

    public function testObjectStreamsRejectDuplicateIdentities(): void
    {
        $value = new IntegerType(1);
        $this->expectException(\InvalidArgumentException::class);
        new ObjectStream(new ObjectStreamEntry(new ObjectNumber(5), $value), new ObjectStreamEntry(new ObjectNumber(6), $value));
    }

    public function testStreamObjectsCannotBePacked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectStreamEntry(new ObjectNumber(5), new StreamObject('abc'));
    }

    public function testEntireIndirectReferenceCannotBePacked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectStreamEntry(new ObjectNumber(5), new IndirectReference(new IntegerType(1)));
    }

    public function testConflictingIdentifierRegistrationIsRejected(): void
    {
        $value = new IntegerType(1);
        $context = new SerializationContext();
        $context->register($value, new ObjectIdentifier(new ObjectNumber(6)));
        $stream = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(5), $value));
        $this->expectException(\LogicException::class);
        $stream->decodedBytes($context);
    }

    public function testCrossReferenceStreamUsesBigEndianEightByteOffsets(): void
    {
        $stream = new CrossReferenceStream(
            new IntegerType(2),
            self::root(),
            null,
            new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(PHP_INT_MAX), new GenerationNumber(65534)),
            new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType(0), new GenerationNumber(65535)),
        );
        self::assertSame([1, 8, 2], $stream->fieldWidths());
        self::assertSame('000000000000000000ffff017ffffffffffffffffffe', bin2hex($stream->decodedBytes()));
        self::assertSame('[0 2]', (string) $stream->dictionary()->get('Index'));
        self::assertSame('[1 8 2]', (string) $stream->dictionary()->get('W'));
    }

    public function testSparseCrossReferenceIndicesAreOrderedAndGrouped(): void
    {
        $stream = new CrossReferenceStream(
            new IntegerType(11),
            self::root(),
            null,
            new CrossReferenceStreamCompressedEntry(new ObjectNumber(10), new ObjectNumber(4), new IntegerType(3)),
            new CrossReferenceStreamInUseEntry(new ObjectNumber(3), new ByteOffset(257)),
            new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType(0), new GenerationNumber(65535)),
            new CrossReferenceStreamInUseEntry(new ObjectNumber(2), new ByteOffset(255)),
        );
        self::assertSame('[0 1 2 2 10 1]', (string) $stream->dictionary()->get('Index'));
        self::assertSame([1, 2, 2], $stream->fieldWidths());
        self::assertSame('000000ffff0100ff000001010100000200040003', bin2hex($stream->decodedBytes()));
        self::assertSame([0, 2, 3, 10], array_map(static fn ($entry): int => $entry->number(), $stream->entries()));
    }

    public function testCrossReferenceStreamFlateFilterRoundTrips(): void
    {
        $plain = new CrossReferenceStream(
            new IntegerType(2),
            self::root(),
            null,
            new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType(0), new GenerationNumber(65535)),
            new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16)),
        );
        $compressed = $plain->withFilters(new FlateDecode());
        self::assertSame($plain->decodedBytes(), gzuncompress($compressed->bytes(new SerializationContext())));
        self::assertStringContainsString('/Filter /FlateDecode', $compressed->toPdf(new SerializationContext()));
    }

    public function testCrossReferenceStreamPreservesTrailerInformation(): void
    {
        $info = new DictionaryType(new Entry(new NameType('Title'), new LiteralString('Object streams')));
        $stream = new CrossReferenceStream(
            new IntegerType(4),
            self::root(),
            new DictionaryType(new Entry(new NameType('Info'), new IndirectReference($info)), new Entry(new NameType('Prev'), new IntegerType(123))),
            new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16)),
        );
        self::assertSame(123, $stream->dictionary()->get('Prev')->value);
        self::assertInstanceOf(IndirectReference::class, $stream->dictionary()->get('Info'));
    }

    public function testCrossReferenceStreamRejectsDuplicateRecords(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceStream(
            new IntegerType(2),
            self::root(),
            null,
            new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16)),
            new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(17)),
        );
    }

    public function testCrossReferenceStreamRejectsNumbersOutsideSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceStream(new IntegerType(2), self::root(), null, new CrossReferenceStreamCompressedEntry(new ObjectNumber(1), new ObjectNumber(3), new IntegerType(0)));
    }

    public function testFreeObjectZeroHasPermanentGeneration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType(0));
    }

    public function testCompressedObjectCannotBeItsOwnContainer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceStreamCompressedEntry(new ObjectNumber(5), new ObjectNumber(5), new IntegerType(0));
    }

    public function testCrossReferenceStreamCannotUseCrypt(): void
    {
        $crypt = new class () implements Filter {
            public function name(): NameType
            {
                return new NameType('Crypt');
            }
            public function parameters(): ?DictionaryType
            {
                return null;
            }
            public function encode(string $bytes): string
            {
                return $bytes;
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceStream(new IntegerType(2), self::root(), null, new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16)), $crypt);
    }

    public function testManagedCrossReferenceDictionaryFieldsCannotBeOverridden(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceStream(
            new IntegerType(2),
            self::root(),
            new DictionaryType(new Entry(new NameType('W'), new ArrayType())),
            new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16))
        );
    }

    public function testWriterEmitsCompressedCatalogWithCrossReferenceStream(): void
    {
        $pages = new PageTreeNode();
        $catalog = new DocumentCatalog($pages);
        $objectStream = (new ObjectStream(new ObjectStreamEntry(new ObjectNumber(10), $catalog)))->withFilters(new FlateDecode());
        $file = new FileStructure(new FileHeader(), new FileBody($objectStream), new IndirectReference($catalog), null, CrossReferenceFormat::Stream);
        $bytes = $file->toPdf();
        self::assertSame($bytes, $file->toPdf());
        self::assertStringContainsString('/Type /ObjStm', $bytes);
        self::assertStringContainsString('/Type /XRef', $bytes);
        self::assertStringContainsString('/Root 10 0 R', $bytes);
        self::assertStringNotContainsString("\n10 0 obj\n", $bytes);
        self::assertStringNotContainsString("\nxref\n", $bytes);
        preg_match('/startxref\n(\d+)\n%%EOF/', $bytes, $match);
        self::assertStringContainsString('/Type /XRef', substr($bytes, (int) $match[1]));
    }

    public function testObjectStreamContainerMustHaveGenerationZero(): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $stream = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(10), $catalog));
        $file = new FileStructure(new FileHeader(), new FileBody(new IndirectObject($stream, new ObjectIdentifier(new ObjectNumber(1), new GenerationNumber(1)))), new IndirectReference($catalog), null, CrossReferenceFormat::Stream);
        $this->expectException(\LogicException::class);
        $file->toPdf();
    }

    public function testClassicTrailerIdRequiresExactlyTwoStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileTrailer(new IntegerType(2), self::root(), new Entry(new NameType('ID'), new ArrayType(new LiteralString('only one'))));
    }

    public function testStreamTrailerIdRequiresByteStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrossReferenceStream(new IntegerType(2), self::root(), new DictionaryType(new Entry(new NameType('ID'), new ArrayType(new NameType('first'), new NameType('second')))), new CrossReferenceStreamInUseEntry(new ObjectNumber(1), new ByteOffset(16)));
    }

    public function testClassicTrailerInfoMustBeIndirect(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileTrailer(new IntegerType(2), self::root(), new Entry(new NameType('Info'), new DictionaryType()));
    }

    public function testClassicTrailerRequiredFieldsCannotBeRemoved(): void
    {
        $trailer = new FileTrailer(new IntegerType(2), self::root());
        $this->expectException(\InvalidArgumentException::class);
        $trailer->without('Root');
    }

    public function testIdentifierDiscoveryReservesLateExplicitReferences(): void
    {
        $explicit = new IntegerType(7);
        $automatic = new IntegerType(8);
        $container = new DictionaryType(new Entry(new NameType('Automatic'), new IndirectReference($automatic)), new Entry(new NameType('Explicit'), new IndirectReference($explicit, new ObjectIdentifier(new ObjectNumber(1)))));
        $context = new SerializationContext();
        $context->prepare($container);
        self::assertSame('1 0', (string) $context->identifier($explicit));
        self::assertNotSame('1 0', (string) $context->identifier($automatic));
        self::assertNotSame('1 0', (string) $context->identifier($container));
    }
}
