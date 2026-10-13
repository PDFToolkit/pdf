<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\ObjectStream;
use PDF\Syntax\FileStructure\ObjectStreamEntry;
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
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncrementalUpdateTest extends TestCase
{
    /** @return iterable<string, array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        yield 'table' => [CrossReferenceFormat::Table];
        yield 'stream' => [CrossReferenceFormat::Stream];
    }

    private function base(CrossReferenceFormat $format, ?DictionaryType $extras = null): FileRevision
    {
        return (new FileStructure(new FileHeader(), new FileBody(new DocumentCatalog(new PageTreeNode())), trailerEntries: $extras, crossReferenceFormat: $format))->toRevision();
    }

    #[DataProvider('formats')]
    public function testAppendedRevisionsPreserveBytesAndChainAbsoluteOffsets(CrossReferenceFormat $format): void
    {
        $base = $this->base($format);
        $one = (new IncrementalUpdate($base, new FileBody(new LiteralString('first delta'))))->toRevision();
        $two = (new IncrementalUpdate($one, new FileBody(new LiteralString('second delta'))))->toRevision();
        self::assertStringStartsWith($base->bytes, $one->bytes);
        self::assertStringStartsWith($one->bytes, $two->bytes);
        self::assertSame(1, substr_count($two->bytes, '%PDF-1.7'));
        self::assertSame(3, substr_count($two->bytes, 'startxref'));
        self::assertStringContainsString('/Prev ' . $base->crossReferenceOffset->value, substr($one->bytes, strlen($base->bytes)));
        self::assertStringContainsString('/Prev ' . $one->crossReferenceOffset->value, substr($two->bytes, strlen($one->bytes)));
        $atXref = substr($two->bytes, $two->crossReferenceOffset->value);
        if ($format === CrossReferenceFormat::Table) {
            self::assertStringStartsWith("xref\n", $atXref);
            preg_match_all('/^(\d{10}) (\d{5}) n $/m', $atXref, $offsets);
            foreach ($offsets[1] as $offset) {
                self::assertMatchesRegularExpression('/^\d+ \d+ obj\n/', substr($two->bytes, (int) $offset));
            }
        } else {
            self::assertMatchesRegularExpression('/^\d+ 0 obj\n<<.*\/Type \/XRef/', $atXref);
        }
        foreach ($two->objects() as $record) {
            self::assertLessThan($two->size->value, $record->identifier->objectNumber->value);
        }
    }

    #[DataProvider('formats')]
    public function testOldAliasesSurviveReplacementsAndSubsequentReferences(CrossReferenceFormat $format): void
    {
        $old = new LiteralString('old value');
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('Private'), new IndirectReference($old)));
        $base = (new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
        $identifier = $base->binding($old)->identifier;
        $new = new LiteralString('new value');
        $one = (new IncrementalUpdate($base, new FileBody(new IndirectObject($new, $identifier))))->toRevision();
        $dictionary = new DictionaryType(new Entry(new NameType('Alias'), new IndirectReference($old)));
        $two = (new IncrementalUpdate($one, new FileBody($dictionary)))->toRevision();
        $delta = substr($two->bytes, strlen($one->bytes));
        self::assertStringContainsString('/Alias ' . $identifier . ' R', $delta);
        self::assertStringNotContainsString('(old value)', $delta);
        self::assertStringNotContainsString('(new value)', $delta);
        self::assertSame($new, $two->reference($old)->target());
        self::assertSame($new, $two->reference($identifier)->target());
        self::assertGreaterThanOrEqual($one->size->value, $two->binding($dictionary)->identifier->objectNumber->value);
    }

    #[DataProvider('formats')]
    public function testTrailerEntriesAreInheritedAndFileIdentifierIsRenewed(CrossReferenceFormat $format): void
    {
        $info = new DictionaryType(new Entry(new NameType('Title'), new LiteralString('original')));
        $extras = new DictionaryType(
            new Entry(new NameType('Info'), new IndirectReference($info)),
            new Entry(new NameType('PrivateKey'), new LiteralString('extension')),
            new Entry(new NameType('ID'), new ArrayType(new HexadecimalString('permanent'), new HexadecimalString('old-changing'))),
        );
        $base = $this->base($format, $extras);
        $one = (new IncrementalUpdate($base, new FileBody(), trailerEntries: new DictionaryType(new Entry(new NameType('NewKey'), new IntegerType(4)))))->toRevision();
        $delta = substr($one->bytes, strlen($base->bytes));
        self::assertStringContainsString('/PrivateKey (extension)', $delta);
        self::assertStringContainsString('/NewKey 4', $delta);
        self::assertStringContainsString('/Info ' . $base->binding($info)->identifier . ' R', $delta);
        self::assertStringNotContainsString('/Title (original)', $delta);
        $id = $one->trailerEntries->get('ID');
        self::assertInstanceOf(ArrayType::class, $id);
        self::assertSame('permanent', $id->items[0]->value);
        self::assertNotSame('old-changing', $id->items[1]->value);
        self::assertSame(16, strlen($id->items[1]->value));
        self::assertFalse($one->trailerEntries->has('Prev'));
    }

    public function testHistoricalMutableTargetsAreNotTraversedUnlessExplicitlyStaged(): void
    {
        $mutable = new class () extends DictionaryType {
            public int $number = 1;
            public function entries(): iterable
            {
                yield new Entry(new NameType('Value'), new IntegerType($this->number));
            }
        };
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('Private'), new IndirectReference($mutable)));
        $base = (new FileStructure(new FileHeader(), new FileBody($catalog)))->toRevision();
        $mutable->number = 999;
        $one = (new IncrementalUpdate($base, new FileBody(new ArrayType(new IndirectReference($mutable)))))->toRevision();
        self::assertStringNotContainsString('/Value 999', $one->bytes);
        $two = (new IncrementalUpdate($one, new FileBody($mutable)))->toRevision();
        self::assertStringContainsString('/Value 999', substr($two->bytes, strlen($one->bytes)));
    }

    public function testRevisionFreezesMutableDirectContainersInsideArrays(): void
    {
        $direct = new class () extends DictionaryType {
            public int $number = 1;
            public function entries(): iterable
            {
                yield new Entry(new NameType('Value'), new IntegerType($this->number));
            }
        };
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('Private'), new ArrayType($direct, $direct)));
        $base = (new FileStructure(new FileHeader(), new FileBody($catalog)))->toRevision();
        $direct->number = 999;
        $snapshot = $base->binding($catalog)->dictionary->get('Private');
        self::assertInstanceOf(ArrayType::class, $snapshot);
        self::assertSame(1, $snapshot->items[0]->get('Value')->value);
        self::assertSame($snapshot->items[0], $snapshot->items[1]);
        $next = (new IncrementalUpdate($base, new FileBody(new LiteralString('unrelated delta'))))->toRevision();
        self::assertStringStartsWith($base->bytes, $next->bytes);
        self::assertSame(1, $next->binding($catalog)->dictionary->get('Private')->items[0]->get('Value')->value);
    }

    public function testChangedRootIsValidatedUsingItsEffectiveReplacement(): void
    {
        $base = $this->base(CrossReferenceFormat::Table);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject(new IntegerType(7), $base->root->identifier))))->toRevision();
    }

    public function testLiveGenerationCannotChange(): void
    {
        $base = $this->base(CrossReferenceFormat::Table);
        $identifier = new ObjectIdentifier($base->root->identifier->objectNumber, new GenerationNumber(1));
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject(new DictionaryType(), $identifier))))->toRevision();
    }

    public function testHistoricalFreeNumbersCannotBeReused(): void
    {
        $catalog = new DocumentCatalog(new PageTreeNode());
        $base = (new FileStructure(new FileHeader(), new FileBody($catalog, new IndirectObject(new IntegerType(42), new ObjectIdentifier(new ObjectNumber(10))))))->toRevision();
        self::assertSame(3, $base->freeListHead->value);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject(new IntegerType(3), new ObjectIdentifier(new ObjectNumber(4))))))->toRevision();
    }

    public function testImplicitReferenceCannotReplaceAnExistingValue(): void
    {
        $base = $this->base(CrossReferenceFormat::Table);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(), new IndirectReference(new DocumentCatalog(new PageTreeNode()), $base->root->identifier)))->toRevision();
    }

    public function testChangingThePermanentIdentifierIsRejected(): void
    {
        $base = $this->base(CrossReferenceFormat::Table, new DictionaryType(new Entry(new NameType('ID'), new ArrayType(new LiteralString('permanent'), new LiteralString('changing')))));
        $this->expectException(\InvalidArgumentException::class);
        (new IncrementalUpdate($base, new FileBody(), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), new ArrayType(new LiteralString('different'), new LiteralString('next'))))))->toRevision();
    }

    public function testDuplicateStagedNumbersAreRejected(): void
    {
        $base = $this->base(CrossReferenceFormat::Table);
        $identifier = new ObjectIdentifier(new ObjectNumber($base->size->value));
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject(new IntegerType(1), $identifier), new IndirectObject(new IntegerType(2), $identifier))))->toRevision();
    }

    public function testCompressedMembersCanBeReplacedNormallyAndInNewContainers(): void
    {
        $old = new LiteralString('packed');
        $number = new ObjectNumber(7);
        $stream = new ObjectStream(new ObjectStreamEntry($number, $old));
        $catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('Private'), new IndirectReference($old)));
        $base = (new FileStructure(new FileHeader(), new FileBody($catalog, $stream), crossReferenceFormat: CrossReferenceFormat::Stream))->toRevision();
        $ordinary = new LiteralString('ordinary');
        $one = (new IncrementalUpdate($base, new FileBody(new IndirectObject($ordinary, $base->binding($old)->identifier))))->toRevision();
        self::assertStringContainsString("7 0 obj\n(ordinary)", substr($one->bytes, strlen($base->bytes)));
        $packedAgain = new LiteralString('packed again');
        $container = new ObjectStream(new ObjectStreamEntry($number, $packedAgain));
        $two = (new IncrementalUpdate($one, new FileBody($container)))->toRevision();
        self::assertStringNotContainsString("7 0 obj\n", substr($two->bytes, strlen($one->bytes)));
        self::assertSame($packedAgain, $two->reference($old)->target());
        $three = (new IncrementalUpdate($two, new FileBody(new ArrayType(new IndirectReference($old)))))->toRevision();
        self::assertStringContainsString('[7 0 R]', substr($three->bytes, strlen($two->bytes)));
        self::assertStringNotContainsString('(packed)', substr($three->bytes, strlen($two->bytes)));
    }

    public function testGeneration65535CannotDescribeALiveObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SerializationContext())->register(new IntegerType(1), new ObjectIdentifier(new ObjectNumber(1), new GenerationNumber(65535)));
    }

    public function testPageTreeReplacementCannotInvalidateHistoricalTypedReferences(): void
    {
        $base = $this->base(CrossReferenceFormat::Table);
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject(new IntegerType(7), $base->pageTreeIdentifier))))->toRevision();
    }

    public function testPageTreeReplacementMustKeepItsDictionaryType(): void
    {
        $base = $this->base(CrossReferenceFormat::Table);
        $invalid = new DictionaryType(new Entry(new NameType('Type'), new NameType('Page')));
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject($invalid, $base->pageTreeIdentifier))))->toRevision();
    }

    public function testObjectStreamMembersCannotReplaceOldStructuralContainers(): void
    {
        $container = new ObjectStream(new ObjectStreamEntry(new ObjectNumber(7), new LiteralString('old member')));
        $base = (new FileStructure(new FileHeader(), new FileBody(new DocumentCatalog(new PageTreeNode()), $container), crossReferenceFormat: CrossReferenceFormat::Stream))->toRevision();
        $malicious = new ObjectStream(new ObjectStreamEntry($base->binding($container)->identifier->objectNumber, new IntegerType(99)));
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody($malicious)))->toRevision();
    }

    public function testNewObjectNumbersMustStartAtGenerationZero(): void
    {
        $base = $this->base(CrossReferenceFormat::Table);
        $identifier = new ObjectIdentifier(new ObjectNumber($base->size->integer()), new GenerationNumber(2));
        $this->expectException(\LogicException::class);
        (new IncrementalUpdate($base, new FileBody(new IndirectObject(new IntegerType(7), $identifier))))->toRevision();
    }

    public function testHistoricalRootMetadataRemainsFrozenWhenBuildersAreMutated(): void
    {
        $pages = new PageTreeNode();
        $base = (new FileStructure(new FileHeader(), new FileBody(new DocumentCatalog($pages))))->toRevision();
        (new PageTreeNode())->addKid($pages);
        // The old model now has a Parent, while its frozen dictionary did not.
        $next = (new IncrementalUpdate($base, new FileBody(new IntegerType(7))))->toRevision();
        self::assertStringStartsWith($base->bytes, $next->bytes);
        self::assertSame((string) $base->pageTreeIdentifier, (string) $next->pageTreeIdentifier);
    }

    public function testDirectTrailerValuesAreNotPromotedToUnusedIndirectObjects(): void
    {
        $id = new ArrayType(new LiteralString('original'), new LiteralString('changing'));
        $private = new LiteralString('direct trailer value');
        $base = $this->base(CrossReferenceFormat::Table, new DictionaryType(
            new Entry(new NameType('ID'), $id),
            new Entry(new NameType('Private'), $private),
        ));
        self::assertCount(2, $base->objects());
        $next = (new IncrementalUpdate($base, new FileBody()))->toRevision();
        self::assertCount(2, $next->objects());
        self::assertStringNotContainsString(' obj', substr($next->bytes, strlen($base->bytes)));
    }

    public function testTrailerEntriesAreFrozenAlongsideTheRevisionBytes(): void
    {
        $trailer = new class () extends DictionaryType {
            public int $revision = 1;
            public function entries(): iterable
            {
                yield new Entry(new NameType('PrivateRevision'), new IntegerType($this->revision));
            }
        };
        $base = $this->base(CrossReferenceFormat::Table, $trailer);
        $trailer->revision = 999;
        $next = (new IncrementalUpdate($base, new FileBody()))->toRevision();
        self::assertStringContainsString('/PrivateRevision 1', substr($next->bytes, strlen($base->bytes)));
        self::assertStringNotContainsString('/PrivateRevision 999', $next->bytes);
    }
}
