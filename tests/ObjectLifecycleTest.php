<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Serialization\FileWriter;
use PDF\Serialization\IncrementalWriter;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\CrossReferenceStreamFreeEntry;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FreeListState;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
use PDF\Syntax\FileStructure\ObjectLifecycleTransition;
use PDF\Syntax\DataStructure\NameTree;
use PDF\Syntax\DataStructure\NameTreeEntry;
use PDF\Document\ResourceDictionary;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\FileStructure\ReleaseIndirectObject;
use PDF\Syntax\FileStructure\ReuseObjectNumber;
use PDF\Syntax\FileStructure\RevisionObject;
use PDF\Syntax\FileStructure\RetiredObjectGeneration;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\FileStructure\RevisionValueSnapshot;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\GenerationNumber;
use PDF\Syntax\Object\IndirectObject\ObjectIdentifier;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectLifecycleTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testReleaseReuseAndASecondReleasePreserveOldGenerations(CrossReferenceFormat $format): void
    {
        $old = new LiteralString('Original value');
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('PrivateValue'), new IndirectReference($old)));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: $format));
        $id = $base->binding($old)->identifier;
        $released = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReleaseIndirectObject($id)));
        self::assertSame($base->bytes, substr($released->bytes, 0, strlen($base->bytes)));
        self::assertSame(1, $released->freeList->entry($id->objectNumber)->generation->value);
        self::assertCount(1, $released->retiredObjects());
        self::assertSame((string) $id, (string) $released->reference($old)->identifier);
        $new = new DictionaryType(new Entry(new NameType('PrivateKind'), new IntegerType(42)));
        $reused = (new IncrementalWriter())->serialize(new IncrementalUpdate($released, new FileBody(), objectOperations: new ReuseObjectNumber($id->objectNumber, $new)));
        $newId = $reused->binding($new)->identifier;
        self::assertSame($id->objectNumber->value, $newId->objectNumber->value);
        self::assertSame(1, $newId->generationNumber->value);
        self::assertSame('dictionary', $reused->binding($new)->objectKind);
        self::assertSame((string) $id, (string) $reused->reference($old)->identifier);
        self::assertFalse(in_array($old, $reused->binding($new)->aliases, true));
        self::assertSame($new, $reused->reference($new)->target());
        $context = new SerializationContext(new ObjectNumber($reused->size->integer()), ...[...$reused->objects(), ...$reused->retiredObjects(), ...$reused->references(), $reused->freeList]);
        self::assertSame((string) $id, (string) $context->knownIdentifier($old));
        self::assertSame((string) $newId, (string) $context->knownIdentifier($new));
        self::assertSame($id . ' R', $context->serialize(new IndirectReference($old)));
        self::assertSame($newId . ' R', $context->serialize(new IndirectReference($new)));
        $again = (new IncrementalWriter())->serialize(new IncrementalUpdate($reused, new FileBody(), objectOperations: new ReleaseIndirectObject($newId)));
        self::assertSame(2, $again->freeList->entry($id->objectNumber)->generation->value);
        self::assertCount(2, $again->retiredObjects());
        self::assertSame((string) $id, (string) $again->reference($old)->identifier);
        self::assertSame((string) $newId, (string) $again->reference($new)->identifier);
    }

    #[DataProvider('formats')]
    public function testUnusedHolesKeepGenerationZeroAndHeadTailLinksFollowOperations(CrossReferenceFormat $format): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $rootId = new ObjectIdentifier(new ObjectNumber(6));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody(new IndirectObject($catalog, $rootId)), new IndirectReference($catalog, $rootId), crossReferenceFormat: $format));
        self::assertSame(2, $base->freeListHead->value);
        $value = new LiteralString('Allocated unused hole');
        $one = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReuseObjectNumber(new ObjectNumber(2), $value)));
        self::assertSame(0, $one->binding($value)->identifier->generationNumber->value);
        self::assertSame(3, $one->freeListHead->value);
        self::assertSame(5, $one->freeList->entry(new ObjectNumber(4))->nextFreeObjectNumber->value);
        self::assertSame(0, $one->freeList->entry(new ObjectNumber(5))->nextFreeObjectNumber->value);
        $two = (new IncrementalWriter())->serialize(new IncrementalUpdate($one, new FileBody(), objectOperations: new ReleaseIndirectObject($one->binding($value)->identifier)));
        self::assertSame(2, $two->freeListHead->value);
        self::assertSame(1, $two->freeList->entry(new ObjectNumber(2))->generation->value);
        self::assertSame(3, $two->freeList->entry(new ObjectNumber(2))->nextFreeObjectNumber->value);
    }

    public function testDeleteIncrementsToPermanentSaturationWithoutWraparound(): void
    {
        $state = new FreeListState(new IntegerType(2), new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType(0), new GenerationNumber(65535)));
        $last = new RevisionObject(new ObjectIdentifier(new ObjectNumber(1), new GenerationNumber(65534)), new LiteralString('Last live generation'));
        $released = $state->released($last);
        self::assertSame(65535, $released->entry(new ObjectNumber(1))->generation->value);
        self::assertSame(1, $released->head()->value);
        $this->expectException(\LogicException::class);
        $released->reused(new ObjectNumber(1));
    }

    public function testRetiredAliasesCannotBeRegisteredOrStagedOrReused(): void
    {
        $old = new LiteralString('Retired');
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('PrivateValue'), new IndirectReference($old)));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog)));
        $id = $base->binding($old)->identifier;
        $released = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReleaseIndirectObject($id)));
        foreach ([new FileBody($old), new FileBody(new IndirectObject($old, $id))] as $body) {
            try {
                (new IncrementalWriter())->serialize(new IncrementalUpdate($released, $body));
                self::fail('A retired value cannot become live through the body.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('retired', strtolower($error->getMessage()));
            }
        }
        $this->expectException(\LogicException::class);
        (new IncrementalWriter())->serialize(new IncrementalUpdate($released, new FileBody(), objectOperations: new ReuseObjectNumber($id->objectNumber, $old)));
    }

    public function testACompressedMemberAndContainerCanBeRetiredTogetherButNotPartly(): void
    {
        $member = new LiteralString('Compressed optional value');
        $container = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(20), $member));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('PrivateValue'), new IndirectReference($member)));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog, $container), crossReferenceFormat: CrossReferenceFormat::Stream));
        self::assertArrayHasKey(20, $base->compressedObjects());
        try {
            (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReleaseIndirectObject($base->binding($container)->identifier)));
            self::fail('The live compressed member still needs its container.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('compressed members remain live', $error->getMessage());
        }
        $released = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReleaseIndirectObject($base->binding($container)->identifier), member: new ReleaseIndirectObject($base->binding($member)->identifier)));
        self::assertSame([], $released->compressedObjects());
        self::assertCount(2, $released->retiredObjects());
        $new = new IntegerType(7);
        $reused = (new IncrementalWriter())->serialize(new IncrementalUpdate($released, new FileBody(), objectOperations: new ReuseObjectNumber(new ObjectNumber(20), $new)));
        self::assertSame(1, $reused->binding($new)->identifier->generationNumber->value);
        self::assertSame('integer', $reused->binding($new)->objectKind);
    }

    public function testCaptureIsIdempotentForTheImmutableContainersItCreated(): void
    {
        $capture = new RevisionValueSnapshot();
        $source = new DictionaryType(new Entry(new NameType('Nested'), new ArrayType(new IntegerType(1))));
        $snapshot = $capture->capture($source);
        self::assertSame($snapshot, $capture->capture($snapshot));
        self::assertInstanceOf(DictionaryType::class, $snapshot);
        $array = $snapshot->get('Nested');
        self::assertInstanceOf(ArrayType::class, $array);
        self::assertSame($array, $capture->capture($array));
    }

    #[DataProvider('formats')]
    public function testNativeRootPagesAndCrossReferenceStreamsAreProtected(CrossReferenceFormat $format): void
    {
        $pages = new PageTreeNode();
        $catalog = new DocumentCatalog($pages);
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: $format));
        $protected = [$base->binding($catalog)->identifier, $base->binding($pages)->identifier];
        foreach ($base->objects() as $record) {
            if ($record->dictionary?->get('Type') instanceof NameType && $record->dictionary->get('Type')->value === 'XRef') {
                $protected[] = $record->identifier;
            }
        }
        foreach ($protected as $id) {
            try {
                new ObjectLifecycleTransition($base, new ReleaseIndirectObject($id));
                self::fail('Required native structure cannot be released.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('cannot be released', $error->getMessage());
            }
        }
    }

    public function testLifecycleOperationsRequireTheCurrentGenerationAndOneOperationPerNumber(): void
    {
        $old = new LiteralString('Original');
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('PrivateValue'), new IndirectReference($old)));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog)));
        $id = $base->binding($old)->identifier;
        $stale = new ObjectIdentifier($id->objectNumber, new GenerationNumber(1));
        try {
            new ObjectLifecycleTransition($base, new ReleaseIndirectObject($stale));
            self::fail('A release must name the current generation.');
        } catch (\OutOfBoundsException $error) {
            self::assertStringContainsString('identifier', $error->getMessage());
        }
        $this->expectException(\LogicException::class);
        new ObjectLifecycleTransition($base, new ReleaseIndirectObject($id), new ReleaseIndirectObject($id));
    }

    public function testAReusedNumberCannotBecomeACompressedMemberOrAContainer(): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $id = new ObjectIdentifier(new ObjectNumber(6));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody(new IndirectObject($catalog, $id)), new IndirectReference($catalog, $id), crossReferenceFormat: CrossReferenceFormat::Stream));
        $value = new LiteralString('Hole generation zero');
        $container = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(2), $value));
        try {
            (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody($container), objectOperations: new ReuseObjectNumber(new ObjectNumber(2), $value)));
            self::fail('Even an unused hole cannot become a compressed member.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('compressed member', $error->getMessage());
        }
        $this->expectException(\LogicException::class);
        new ObjectLifecycleTransition($base, new ReuseObjectNumber(new ObjectNumber(2), $container));
    }

    public function testAnObsoleteReferenceCannotBeForgedWithTheReusedGeneration(): void
    {
        $old = new LiteralString('Original');
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('PrivateValue'), new IndirectReference($old)));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog)));
        $id = $base->binding($old)->identifier;
        $released = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReleaseIndirectObject($id)));
        $new = new LiteralString('New identity');
        $reused = (new IncrementalWriter())->serialize(new IncrementalUpdate($released, new FileBody(), objectOperations: new ReuseObjectNumber($id->objectNumber, $new)));
        $context = new SerializationContext(new ObjectNumber($reused->size->integer()), ...[...$reused->objects(), ...$reused->retiredObjects(), $reused->freeList]);
        $this->expectException(\LogicException::class);
        $context->serialize(new IndirectReference($old, $reused->binding($new)->identifier));
    }

    public function testMiddleTailReuseAndMultipleReleaseRebuildACompleteAcyclicFreeChain(): void
    {
        $state = new FreeListState(
            new IntegerType(6),
            new CrossReferenceStreamFreeEntry(new IntegerType(0), new IntegerType(1), new GenerationNumber(65535)),
            new CrossReferenceStreamFreeEntry(new IntegerType(1), new IntegerType(3)),
            new CrossReferenceStreamFreeEntry(new IntegerType(3), new IntegerType(5), new GenerationNumber(2)),
            new CrossReferenceStreamFreeEntry(new IntegerType(5), new IntegerType(0), new GenerationNumber(65535)),
        );
        $middle = $state->reused(new ObjectNumber(3));
        self::assertSame(5, $middle->entry(new ObjectNumber(1))->nextFreeObjectNumber->integer());
        $released = $middle->released(new RevisionObject(new ObjectIdentifier(new ObjectNumber(2)), new IntegerType(2)), new RevisionObject(new ObjectIdentifier(new ObjectNumber(4), new GenerationNumber(4)), new IntegerType(4)));
        self::assertSame(1, $released->head()->integer());
        self::assertSame([0, 1, 2, 4, 5], array_map(fn (CrossReferenceStreamFreeEntry $entry): int => $entry->number(), $released->entries()));
        self::assertSame(5, $released->entry(new ObjectNumber(4))->generation->value);
        self::assertSame(0, $released->entry(new ObjectNumber(5))->nextFreeObjectNumber->integer());
    }

    public function testAnUnusedHoleCannotBecomeCompressedInALaterRevisionEither(): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $id = new ObjectIdentifier(new ObjectNumber(6));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody(new IndirectObject($catalog, $id)), new IndirectReference($catalog, $id), crossReferenceFormat: CrossReferenceFormat::Stream));
        $value = new LiteralString('Allocated generation-zero hole');
        $reused = (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReuseObjectNumber(new ObjectNumber(2), $value)));
        self::assertTrue($reused->wasReusedNumber(2));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('compressed member');
        (new IncrementalWriter())->serialize(new IncrementalUpdate($reused, new FileBody(new ObjectStream(new ObjectStreamEntry(new ObjectNumber(2), $value)))));
    }

    public function testDiscoveryRetainsTemporaryEntryValuesFromPageAndNameTrees(): void
    {
        $specification = new DictionaryType(new Entry(new NameType('Type'), new NameType('Filespec')), new Entry(new NameType('F'), new LiteralString('sample.bin')));
        $tree = new NameTree(new NameTreeEntry(new LiteralString('sample'), new IndirectReference($specification)));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('Names'), new DictionaryType(new Entry(new NameType('EmbeddedFiles'), new IndirectReference($tree)))));
        $context = new SerializationContext();
        $context->prepareFile(new FileBody($catalog), new IndirectReference($catalog));
        self::assertNotNull($context->knownIdentifier($specification));
        self::assertNotNull($context->knownIdentifier($tree));
    }

    public function testRequiredPageResourcesCannotBeFreedWhileThePageStillReferencesThem(): void
    {
        $resources = new ResourceDictionary();
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new IndirectReference($resources), new StreamObject('q Q'));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody($catalog)));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Resources');
        (new IncrementalWriter())->serialize(new IncrementalUpdate($base, new FileBody(), objectOperations: new ReleaseIndirectObject($base->binding($resources)->identifier)));
    }

    public function testAReusePlanCannotBindOneFreshIdentityToTwoFreeNumbers(): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $id = new ObjectIdentifier(new ObjectNumber(6));
        $base = (new FileWriter())->revision(new FileStructure(new FileHeader(), new FileBody(new IndirectObject($catalog, $id)), new IndirectReference($catalog, $id)));
        $value = new LiteralString('One identity');
        $this->expectException(\LogicException::class);
        new ObjectLifecycleTransition($base, new ReuseObjectNumber(new ObjectNumber(2), $value), new ReuseObjectNumber(new ObjectNumber(3), $value));
    }

    public function testStreamEmissionProofsNeverMixRetiredAndReusedGenerations(): void
    {
        $old = new StreamObject('Old generation');
        $new = new StreamObject('New generation');
        $number = new ObjectNumber(1);
        $oldId = new ObjectIdentifier($number);
        $newId = new ObjectIdentifier($number, new GenerationNumber(1));
        $context = new SerializationContext(new ObjectNumber(2), new RevisionObject($newId, $new), new RetiredObjectGeneration(new RevisionObject($oldId, $old)));
        $context->serializeIndirect($new);
        $snapshot = $context->serializedSnapshot($new)->value;
        self::assertInstanceOf(RevisionStreamSnapshot::class, $snapshot);
        self::assertSame('New generation', $snapshot->encodedBytes);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('actual recorded emission');
        $context->serializedSnapshot($old);
    }
}
