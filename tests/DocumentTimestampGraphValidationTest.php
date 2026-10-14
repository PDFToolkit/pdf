<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\DocumentGraphContext;
use PDF\Document\DocumentGraphValidation;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\DocumentTimestampGraphValidation;
use PDF\Document\Signatures\PendingDocumentTimestamp;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureValue;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Serialization\SignatureWriter;
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
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentTimestampGraphValidationTest extends TestCase
{
    /** @return iterable<string,array{CrossReferenceFormat}> */
    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    /** @return array{FileRevision,DocumentCatalog,PendingDocumentTimestamp} */
    private function finalized(CrossReferenceFormat $format): array
    {
        $pending = new PendingDocumentTimestamp(new SignatureCapacity(256));
        $field = new DictionaryType($this->entry('FT', new NameType('Sig')), $this->entry('T', new TextString('timestamp')), $this->entry('V', new IndirectReference($pending)));
        $form = new DictionaryType($this->entry('Fields', new ArrayType(new IndirectReference($field))));
        $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), new StreamObject("q\nQ"));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $this->entry('AcroForm', new IndirectReference($form)), $this->entry('Version', new NameType('2.0')));
        $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader('1.7'), new FileBody($catalog), crossReferenceFormat: $format), $pending);
        // Deterministic external-handler output tests byte preservation only.
        // Cryptographic RFC3161 token verification has its own backend suite.
        return [$prepared->finalize(new SignatureValue("\x30\x00"))->revision, $catalog, $pending];
    }

    #[DataProvider('formats')]
    public function testFinalizedRawTimestampsRemainValidAcrossRetainedRevisionsWithoutDss(CrossReferenceFormat $format): void
    {
        [$signed, , $pending] = $this->finalized($format);
        self::assertNotInstanceOf(PendingDocumentTimestamp::class, $signed->binding($pending)->value);
        self::assertFalse($signed->binding($signed->root->target())->dictionary->has('DSS'));
        $first = (new IncrementalUpdate($signed, new FileBody()))->toRevision();
        $second = (new IncrementalUpdate($first, new FileBody()))->toRevision();
        self::assertStringStartsWith($signed->bytes, $first->bytes);
        self::assertStringStartsWith($first->bytes, $second->bytes);
        self::assertSame($signed->binding($pending)->dictionary->get('ByteRange'), $second->binding($pending)->dictionary->get('ByteRange'));
    }

    #[DataProvider('formats')]
    public function testFrozenRawTimestampStillRequiresPdf20WhenItsNativeClassWasReplaced(CrossReferenceFormat $format): void
    {
        [$signed, $catalog, $pending] = $this->finalized($format);
        self::assertSame('1.7', $signed->header->version->value);
        self::assertNotInstanceOf(PendingDocumentTimestamp::class, $signed->binding($pending)->value);
        $replacement = $signed->binding($catalog)->dictionary->with($this->entry('Version', new NameType('1.7')));
        $context = new SerializationContext(new ObjectNumber($signed->size->integer()), ...[...$signed->objects(), ...$signed->references(), $signed->freeList]);
        $context->prepare(new IndirectObject($replacement, $signed->binding($catalog)->identifier));
        // Exercise the graph itself, independently of the additional incremental
        // version policy that already rejects an emitted version downgrade.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Document timestamp dictionaries');
        (new DocumentGraphValidation($context, $replacement, ...$signed->objects()))->validate($signed->header, previous: $signed);
    }

    /** @return iterable<string,array{string,?BasicType}> */
    public static function malformedEntries(): iterable
    {
        yield 'missing required Type' => ['Type', null];
        yield 'RFC3161 on Sig' => ['Type', new NameType('Sig')];
        yield 'indirect Type' => ['Type', new IndirectReference(new NameType('DocTimeStamp'))];
        yield 'indirect SubFilter' => ['SubFilter', new IndirectReference(new NameType('ETSI.RFC3161'))];
        yield 'missing Filter' => ['Filter', null];
        yield 'indirect Filter' => ['Filter', new IndirectReference(new NameType('Adobe.PPKLite'))];
        yield 'literal Contents token' => ['Contents', new LiteralString("\x30\x00")];
        yield 'indirect Contents' => ['Contents', new IndirectReference(new HexadecimalString("\x30\x00"))];
        yield 'empty Contents' => ['Contents', new HexadecimalString('')];
        yield 'indirect ByteRange' => ['ByteRange', new IndirectReference(new ArrayType(new IntegerType(0), new IntegerType(10), new IntegerType(16), new IntegerType(20)))];
        yield 'three offsets' => ['ByteRange', new ArrayType(new IntegerType(0), new IntegerType(10), new IntegerType(16))];
        yield 'nonzero start' => ['ByteRange', new ArrayType(new IntegerType(1), new IntegerType(10), new IntegerType(16), new IntegerType(20))];
        yield 'extra hole byte' => ['ByteRange', new ArrayType(new IntegerType(0), new IntegerType(10), new IntegerType(17), new IntegerType(20))];
        yield 'indirect member' => ['ByteRange', new ArrayType(new IntegerType(0), new IndirectReference(new IntegerType(10)), new IntegerType(16), new IntegerType(20))];
        yield 'overflow' => ['ByteRange', new ArrayType(new IntegerType(0), new IntegerType(10), new IntegerType(16), new IntegerType(PHP_INT_MAX))];
        yield 'raw zero reservation' => ['ByteRange', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0))];
        yield 'Reference transform' => ['Reference', new ArrayType()];
        yield 'Changes count' => ['Changes', new ArrayType()];
        yield 'Cert outside CMS' => ['Cert', new HexadecimalString('certificate')];
        yield 'indirect private value' => ['Private', new IndirectReference(new IntegerType(1))];
    }

    #[DataProvider('malformedEntries')]
    public function testRawTimestampsCannotBypassTheirDirectValueAndByteRangeContract(string $key, ?BasicType $value): void
    {
        $timestamp = $this->raw();
        $timestamp = $value === null ? $timestamp->without($key) : $timestamp->with($this->entry($key, $value));
        [$context, $graph, $catalog] = $this->graph($timestamp);
        $this->expectException(\InvalidArgumentException::class);
        (new DocumentTimestampGraphValidation())->validate($timestamp, $timestamp, $graph, new EffectivePdfVersion(new FileHeader('2.0'), $catalog));
    }

    public function testRawTimestampCannotAppearInPdf17(): void
    {
        $timestamp = $this->raw();
        [, $graph, $catalog] = $this->graph($timestamp);
        $this->expectException(\LogicException::class);
        (new DocumentTimestampGraphValidation())->validate($timestamp, $timestamp, $graph, new EffectivePdfVersion(new FileHeader('1.7'), $catalog));
    }

    public function testCustomTimestampSubfiltersRemainExplicitAndNullMeansAbsent(): void
    {
        $timestamp = $this->raw()->with($this->entry('SubFilter', new NameType('ACME.Timestamp')))->with($this->entry('Reference', new ArrayType()));
        [, $graph, $catalog] = $this->graph($timestamp);
        (new DocumentTimestampGraphValidation())->validate($timestamp, $timestamp, $graph, new EffectivePdfVersion(new FileHeader('2.0'), $catalog));
        $standard = $this->raw()->with($this->entry('Reference', new NullType()));
        [, $graph, $catalog] = $this->graph($standard);
        (new DocumentTimestampGraphValidation())->validate($standard, $standard, $graph, new EffectivePdfVersion(new FileHeader('2.0'), $catalog));
        self::assertSame('ACME.Timestamp', $timestamp->get('SubFilter')->value);
    }

    public function testRetainedTimestampCannotMoveTheExcludedTokenWithoutChangingBytes(): void
    {
        [$signed, , $pending] = $this->finalized(CrossReferenceFormat::Table);
        $record = $signed->binding($pending);
        $range = $record->dictionary->get('ByteRange');
        $shifted = new ArrayType(new IntegerType(0), new IntegerType($range->items[1]->value + 1), new IntegerType($range->items[2]->value + 1), new IntegerType($range->items[3]->value - 1));
        $frozen = $signed->withReservedBytes($signed->bytes, $record->replacedBy($record->dictionary->with($this->entry('ByteRange', $shifted))));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actual frozen Contents token');
        (new IncrementalUpdate($frozen, new FileBody()))->toRevision();
    }

    private function raw(): DictionaryType
    {
        return new DictionaryType($this->entry('Type', new NameType('DocTimeStamp')), $this->entry('SubFilter', new NameType('ETSI.RFC3161')), $this->entry('Filter', new NameType('Adobe.PPKLite')), $this->entry('Contents', new HexadecimalString("\x30\x00")), $this->entry('ByteRange', new ArrayType(new IntegerType(0), new IntegerType(10), new IntegerType(16), new IntegerType(20))));
    }

    /** @return array{SerializationContext,DocumentGraphContext,DictionaryType} */
    private function graph(DictionaryType $timestamp): array
    {
        $catalog = new DictionaryType($this->entry('Timestamp', new IndirectReference($timestamp)));
        $context = new SerializationContext();
        $context->prepare($catalog);
        return [$context, new DocumentGraphContext($context, $catalog), $catalog];
    }

    private function entry(string $name, BasicType $value): Entry
    {
        return new Entry(new NameType($name), $value);
    }
}
