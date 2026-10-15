<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Collections\Collection;
use PDF\Document\Collections\CollectionView;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Files as F;
use PDF\Document\Parts as P;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentPartsAndPayloadTest extends TestCase
{
    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
    private function page(P\DocumentPart $part): PageObject
    {
        return new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, new P\DocumentPartEntry($part));
    }
    /** @return array{DocumentCatalog, P\DocumentPartRoot, list<P\DocumentPart>, list<PageObject>} */
    private function parts(): array
    {
        $first = new P\DocumentPart(new P\DocumentPartMetadata($this->entry('Customer', new TextString('Alice')), $this->entry('Job', new DictionaryType($this->entry('Copies', new IntegerType(2))))));
        $second = new P\DocumentPart(new P\DocumentPartMetadata($this->entry('Customer', new TextString('Bob'))));
        $node = (new P\DocumentPart())->addChildren($first, $second);
        $root = new P\DocumentPartRoot($node, new IntegerType(1), new NameType('Job'), new NameType('Customer'));
        $a = $this->page($first);
        $b = $this->page($first);
        $c = $this->page($second);
        $pages = (new PageTreeNode())->addKid($a)->addKid($b)->addKid($c);
        $first->setPageRange($a, $b);
        $second->setPageRange($c);
        return [new DocumentCatalog($pages, $root->catalogEntry()), $root, [$first, $second], [$a, $b, $c]];
    }

    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }
    #[DataProvider('formats')]
    public function testDocumentPartsDeriveParentsAndRangeEndpoints(CrossReferenceFormat $format): void
    {
        [$catalog, $root, $parts, $pages] = $this->parts();
        $revision = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
        self::assertSame('2.0', $revision->header->version->value);
        $children = $root->root->get('DParts');
        self::assertInstanceOf(ArrayType::class, $children);
        self::assertCount(2, $children);
        foreach ($children->items as $index => $child) {
            self::assertInstanceOf(IndirectReference::class, $child);
            self::assertSame($parts[$index], $child->target());
        }
        $start = $parts[0]->get('Start');
        $end = $parts[0]->get('End');
        self::assertInstanceOf(IndirectReference::class, $start);
        self::assertInstanceOf(IndirectReference::class, $end);
        self::assertSame($pages[0], $start->target());
        self::assertSame($pages[1], $end->target());
        self::assertNull($parts[1]->get('End'));
        $part = $pages[2]->get('DPart');
        self::assertInstanceOf(IndirectReference::class, $part);
        self::assertSame($parts[1], $part->target());
        self::assertStringContainsString('%%EOF', $revision->bytes);
    }

    public function testDocumentPartsRequirePdf20(): void
    {
        [$catalog] = $this->parts();
        $this->expectException(\LogicException::class);
        (new FileStructure(new FileHeader('1.7'), new FileBody($catalog)))->toPdf();
    }

    public function testDpmRejectsInvalidUtf8NamesEvenInsideNestedArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new P\DocumentPartMetadata($this->entry('Values', new ArrayType(new ArrayType(new NameType("\xff")))));
    }

    public function testDpmRejectsInvalidNestedXmlNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new P\DocumentPartMetadata($this->entry('Job', new DictionaryType($this->entry('not a name', new IntegerType(2)))));
    }

    public function testDpmAllowsIndirectValuesWithPermittedResolvedTypes(): void
    {
        $array = new ArrayType(new IndirectReference(new TextString('A permitted text value')), new IndirectReference(new NameType('Révisé')));
        $metadata = new P\DocumentPartMetadata($this->entry('Values', new IndirectReference($array)));
        $part = new P\DocumentPart($metadata);
        $root = new P\DocumentPartRoot($part);
        $page = $this->page($part);
        $pages = (new PageTreeNode())->addKid($page);
        $part->setPageRange($page);
        $catalog = new DocumentCatalog($pages, $root->catalogEntry());
        $bytes = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
        self::assertStringContainsString('(A permitted text value)', $bytes);
        self::assertStringContainsString('/R#C3#A9vis#C3#A9', $bytes);
    }

    public function testDPartConstructorRejectsObsoleteXmpMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new P\DocumentPart(null, $this->entry('Metadata', new IndirectReference(new StreamObject('xmp'))));
    }

    public function testIncrementalDPartRejectsObsoleteNestedChildArrays(): void
    {
        [$catalog, $root] = $this->parts();
        $previous = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $replacement = new DictionaryType(...iterator_to_array($root->root->entries(), false));
        $children = $replacement->get('DParts');
        self::assertInstanceOf(ArrayType::class, $children);
        $replacement = $replacement->with($this->entry('DParts', new ArrayType($children)));
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($root->root)->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 #612');
        $update->toRevision();
    }

    public function testIncrementalDPartRejectsObsoleteXmpMetadata(): void
    {
        [$catalog, , $parts] = $this->parts();
        $previous = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $metadata = new StreamObject('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>', new DictionaryType($this->entry('Type', new NameType('Metadata')), $this->entry('Subtype', new NameType('XML'))));
        $replacement = new DictionaryType(...iterator_to_array($parts[0]->entries(), false));
        $replacement = $replacement->with($this->entry('Metadata', new IndirectReference($metadata)));
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($parts[0])->identifier), $metadata));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 #290');
        $update->toRevision();
    }

    public function testIncrementalDpmRejectsNonUtf8Names(): void
    {
        [$catalog, , $parts] = $this->parts();
        $previous = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $replacement = new DictionaryType(...iterator_to_array($parts[0]->entries(), false));
        $replacement = $replacement->with($this->entry('DPM', new DictionaryType($this->entry('State', new NameType("\xff")))));
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($parts[0])->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EC3 #86');
        $update->toRevision();
    }

    public function testDocumentPartsCannotRepeatChildrenOrBecomeTheirOwnAncestors(): void
    {
        $parent = new P\DocumentPart();
        $child = new P\DocumentPart();
        try {
            $parent->addChildren($child, $child);
            self::fail('Repeated children accepted.');
        } catch (\InvalidArgumentException) {
        }
        // A rejected batch did not attach any child.
        $parent->addChildren($child);
        $this->expectException(\InvalidArgumentException::class);
        $child->addChildren($parent);
    }

    public function testIncrementalDPartCannotMoveStartAcrossPreviousPartRange(): void
    {
        [$catalog, , $parts, $pages] = $this->parts();
        $previous = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $replacement = new DictionaryType(...iterator_to_array($parts[1]->entries(), false));
        $replacement = $replacement->with($this->entry('Start', new IndirectReference($pages[0])));
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($parts[1])->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('page order');
        $update->toRevision();
    }

    public function testIncrementalDPartCannotDropReciprocalPageLink(): void
    {
        [$catalog, , , $pages] = $this->parts();
        $previous = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $replacement = (new DictionaryType(...iterator_to_array($pages[2]->entries(), false)))->without('DPart');
        $update = new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($pages[2])->identifier)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reciprocal DPart');
        $update->toRevision();
    }

    public function testDPartRootNamesHaveExactlyOneEntryPerLevel(): void
    {
        [$catalog, $root] = $this->parts();
        $previous = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $replacement = (new DictionaryType(...iterator_to_array($root->entries(), false)))->with($this->entry('NodeNameList', new ArrayType(new NameType('OnlyOne'))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one XML name');
        (new IncrementalUpdate($previous, new FileBody(new IndirectObject($replacement, $previous->binding($root)->identifier))))->toRevision();
    }

    /** @return array{DocumentCatalog, F\FileSpecification, Collection} */
    private function wrapper(): array
    {
        $payload = new F\EmbeddedFileStream('opaque encrypted payload supplied by caller', new NameType('application/pdf'), modificationDate: new Date(new \DateTimeImmutable('2026-10-14T00:00:00Z')));
        $file = new F\FileSpecification(new LiteralString('payload.pdf'), new F\FileSpecificationOptions(embedded: new F\EmbeddedFileBindings(new F\EmbeddedFileBinding(F\EmbeddedFileKey::File, $payload))), new F\AssociatedFileRelationship(F\AFRelationship::EncryptedPayload), encryptedPayload: new F\EncryptedPayload(new NameType('AcmeSecurity'), new NameType('1.0')));
        $files = new F\EmbeddedFiles(new F\EmbeddedFileAttachment(new LiteralString('payload.pdf'), $file));
        $collection = new Collection(view: CollectionView::Hidden, initialDocument: new LiteralString('payload.pdf'));
        $catalog = new DocumentCatalog(new PageTreeNode(), new F\AssociatedFilesEntry(new F\AssociatedFiles(new IndirectReference($file))), $this->entry('Names', new DictionaryType($files->nameDictionaryEntry())), $collection->catalogEntry());
        return [$catalog, $file, $collection];
    }

    public function testEncryptedPayloadDescriptorAndWrapperAreNativeObjects(): void
    {
        [$catalog, $file] = $this->wrapper();
        $pdf = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
        self::assertInstanceOf(F\EncryptedPayload::class, $file->get('EP'));
        self::assertStringContainsString('/Type /EncryptedPayload /Subtype /AcmeSecurity /Version /1.0', $pdf);
        self::assertStringContainsString('/View /H /D (payload.pdf)', $pdf);
        self::assertStringNotContainsString('/Encrypt ', $pdf);
    }

    public function testEncryptedWrapperCannotItselfBeEncrypted(): void
    {
        [$catalog] = $this->wrapper();
        $context = new SerializationContext();
        $context->prepareFile(new FileBody($catalog), new IndirectReference($catalog), null);
        $graph = new DocumentGraphContext($context, $catalog);
        $this->expectException(\InvalidArgumentException::class);
        (new F\EncryptedPayloadGraphValidation())->validate($graph, new EffectivePdfVersion(new FileHeader('2.0'), $graph->catalog), true);
    }

    /** @return iterable<string, array{string, BasicType}> */
    public static function badCollections(): iterable
    {
        yield 'visible' => ['View', new NameType('D')];
        yield 'missing-key' => ['D', new LiteralString('absent.pdf')];
    }
    #[DataProvider('badCollections')]
    public function testPayloadCannotLoseItsHiddenInitialDocument(string $key, BasicType $value): void
    {
        [$catalog, , $collection] = $this->wrapper();
        $replacement = new DictionaryType(...iterator_to_array($collection->entries(), false));
        $catalog = $catalog->with($this->entry('Collection', $replacement->with($this->entry($key, $value))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hidden Collection');
        (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
    }

    public function testPayloadNameTreeMustContainExactlyOneMatchingPayload(): void
    {
        [$catalog] = $this->wrapper();
        $catalog = $catalog->without('Names');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly its one payload');
        (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
    }

    public function testEncryptedPayloadVersionIsANameOfDottedIntegers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new F\EncryptedPayload(new NameType('Handler'), new NameType('1.2-beta'));
    }
}
