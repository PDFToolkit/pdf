<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\LinearizedWriter;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Linearization\HintStreamSplit;
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
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinearizedRevisionTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testSnapshotsCaptureNormalizedValuesAndPermitFollowingUpdates(CrossReferenceFormat $format): void
    {
        [$file, $catalog, $pages, $leaf, $content] = $this->document($format);
        $revision = (new LinearizedWriter(hintSplit: new HintStreamSplit(new IntegerType(1))))->revision($file);
        self::assertNull($revision->previous);
        self::assertNotSame('900 0', (string) $revision->binding($catalog)->identifier);
        self::assertFalse($revision->binding($pages)->dictionary?->has('MediaBox'));
        self::assertTrue($revision->binding($leaf)->dictionary?->has('MediaBox'));
        self::assertTrue($revision->binding($leaf)->dictionary->has('Resources'));
        foreach ($revision->spans() as $span) {
            self::assertStringStartsWith($span->identifier . " obj\n", substr($revision->bytes, $span->offset->value, $span->length));
        }
        $replacement = new StreamObject('q 2 w Q');
        $update = (new IncrementalWriter())->serialize(new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($content)->identifier))));
        self::assertSame($revision, $update->previous);
        self::assertSame($revision->bytes, substr($update->bytes, 0, strlen($revision->bytes)));
        self::assertSame($replacement, $update->reference($content)->target());
        self::assertSame((string) $revision->binding($content)->identifier, (string) $update->binding($content)->identifier);
        self::assertSame($revision->span($leaf), $update->span($leaf));
        self::assertInstanceOf(RevisionStreamSnapshot::class, $update->binding($content)->snapshot);
        self::assertSame('q 2 w Q', $update->binding($content)->snapshot->encodedBytes);
        $catalogUpdate = $catalog->with(new Entry(new NameType('PrivateRevision'), new IntegerType(2)));
        $second = (new IncrementalWriter())->serialize(new IncrementalUpdate($update, new FileBody(new IndirectObject($catalogUpdate, $update->binding($catalog)->identifier))));
        self::assertSame($update, $second->previous);
        self::assertSame($update->bytes, substr($second->bytes, 0, strlen($update->bytes)));
        $version = $second->binding($catalog)->dictionary?->get('PrivateRevision');
        self::assertInstanceOf(IntegerType::class, $version);
        self::assertSame(2, $version->value);
        self::assertStringContainsString('/Prev ' . $update->crossReferenceOffset->value, substr($second->bytes, strlen($update->bytes)));
        if (preg_match('/\/L (\d+)/', $revision->bytes, $length) !== 1) {
            self::fail('The initial file declares its actual linearized length.');
        }
        self::assertSame(strlen($revision->bytes), (int) $length[1]);
        self::assertLessThan(strlen($second->bytes), (int) $length[1]);
    }

    public function testTheSameSerializationCallProvidesTheFrozenStreamPayload(): void
    {
        [$file, $catalog] = $this->document(CrossReferenceFormat::Table);
        $stream = new class ('') extends StreamObject {
            public int $calls = 0;
            public function bytes(SerializationContext $context): string
            {
                return 'Payload ' . ++$this->calls;
            }
        };
        $catalog = $catalog->with(new Entry(new NameType('PrivateStream'), new IndirectReference($stream)));
        $identifier = new ObjectIdentifier(new ObjectNumber(900));
        $file = new FileStructure($file->header, new FileBody(new IndirectObject($catalog, $identifier)), new IndirectReference($catalog, $identifier));
        foreach ([new FileWriter(), new LinearizedWriter()] as $writer) {
            $stream->calls = 0;
            $revision = $writer->revision($file);
            self::assertSame(1, $stream->calls);
            $snapshot = $revision->binding($stream)->snapshot;
            self::assertInstanceOf(RevisionStreamSnapshot::class, $snapshot);
            self::assertSame('Payload 1', $snapshot->encodedBytes);
            $length = $snapshot->dictionary()->get('Length');
            self::assertInstanceOf(IntegerType::class, $length);
            self::assertSame(9, $length->value);
            self::assertStringContainsString("\nstream\nPayload 1\nendstream", $revision->bytes);
            $stream->calls = 20;
            self::assertSame('Payload 1', $snapshot->encodedBytes);
        }
    }

    public function testNewContradictoryReferencesCannotImpersonateOriginalRemappedInstances(): void
    {
        [$file, $catalog, $pages] = $this->document(CrossReferenceFormat::Table);
        $revision = (new LinearizedWriter())->revision($file);
        $wrong = $catalog->with(new Entry(new NameType('PrivatePages'), new IndirectReference($pages, new ObjectIdentifier(new ObjectNumber(800)))));
        $this->expectException(\LogicException::class);
        (new IncrementalWriter())->serialize(new IncrementalUpdate($revision, new FileBody(new IndirectObject($wrong, $revision->binding($catalog)->identifier))));
    }

    public function testStandaloneIndirectStreamCapturesItsEmissionWithoutPriorDiscovery(): void
    {
        $stream = new StreamObject('Standalone stream', new DictionaryType(new Entry(new NameType('Label'), new LiteralString('Standalone dictionary'))));
        $context = new SerializationContext();
        $bytes = $context->serializeIndirect($stream);
        self::assertStringContainsString("\nstream\nStandalone stream\nendstream", $bytes);
        self::assertSame('1 0', (string) $context->knownIdentifier($stream));
        $snapshot = $context->serializedSnapshot($stream)->value;
        self::assertInstanceOf(RevisionStreamSnapshot::class, $snapshot);
        self::assertSame('Standalone stream', $snapshot->encodedBytes);
        $length = $snapshot->dictionary()->get('Length');
        self::assertInstanceOf(IntegerType::class, $length);
        self::assertSame(17, $length->integer());
    }

    public function testUnboundStreamBodySerializationDoesNotInventARevisionEmission(): void
    {
        $stream = new StreamObject('Body only');
        $context = new SerializationContext();
        self::assertStringContainsString("\nstream\nBody only\nendstream", $stream->toPdf($context));
        self::assertNull($context->knownIdentifier($stream));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('actual recorded emission');
        $context->serializedSnapshot($stream);
    }

    #[DataProvider('formats')]
    public function testRestagingAnOriginalLeafRetainsItsEmittedInheritedValues(CrossReferenceFormat $format): void
    {
        [$file, , $pages, $leaf] = $this->document($format);
        $base = (new LinearizedWriter())->revision($file);
        $update = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody($leaf)));
        $dictionary = $update->binding($leaf)->dictionary;
        self::assertInstanceOf(DictionaryType::class, $dictionary);
        self::assertTrue($dictionary->has('MediaBox'));
        self::assertTrue($dictionary->has('Resources'));
        self::assertFalse($update->binding($pages)->dictionary?->has('MediaBox'));
        self::assertNull($leaf->get('MediaBox'));
        self::assertNotNull($pages->get('MediaBox'));
    }

    /** @return array{FileStructure, DictionaryType, PageTreeNode, PageObject, StreamObject} */
    private function document(CrossReferenceFormat $format): array
    {
        $resources = new ResourceDictionary();
        $pages = new PageTreeNode(
            new Entry(new NameType('MediaBox'), new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100))),
            new Entry(new NameType('Resources'), new IndirectReference($resources, new ObjectIdentifier(new ObjectNumber(700)))),
        );
        $content = new StreamObject('q Q');
        $leaf = new PageObject(null, null, $content);
        $pages->addKid($leaf);
        $pages->addKid(new PageObject(null, null, new StreamObject('q 1 w Q')));
        $catalog = new DictionaryType(new Entry(new NameType('Type'), new NameType('Catalog')), new Entry(new NameType('Pages'), new IndirectReference($pages, new ObjectIdentifier(new ObjectNumber(800)))), new Entry(new NameType('PrivateTitle'), new LiteralString('Snapshot')));
        $identifier = new ObjectIdentifier(new ObjectNumber(900));
        return [new FileStructure(new FileHeader('1.7'), new FileBody(new IndirectObject($catalog, $identifier)), new IndirectReference($catalog, $identifier), null, $format), $catalog, $pages, $leaf, $content];
    }
}
