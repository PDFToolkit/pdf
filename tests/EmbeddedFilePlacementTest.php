<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Files as F;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
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

final class EmbeddedFilePlacementTest extends TestCase
{
    public function testFileSpecificationDoesNotNeedTheEmbeddedFilesNameTree(): void
    {
        $file = $this->file(new F\EmbeddedFileStream('attached bytes'));
        $catalog = $this->catalog($this->entry('ACME_File', new IndirectReference($file)));
        $pdf = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
        self::assertStringContainsString('/EF', $pdf);
        self::assertStringNotContainsString('/EmbeddedFiles', $pdf);
    }

    public function testPrivateEfSpellingCannotMakeAnEmbeddedStreamReferenceLegal(): void
    {
        $stream = new F\EmbeddedFileStream('attached bytes');
        $private = new DictionaryType($this->entry('EF', new DictionaryType($this->entry('F', new IndirectReference($stream)))));
        $catalog = $this->catalog($this->entry('ACME_Private', $private));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filespec EF or RF');
        (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
    }

    #[DataProvider('formats')]
    public function testIncrementalPrivateReferenceCannotReuseAnEmbeddedStream(CrossReferenceFormat $format): void
    {
        // A generic stream without optional Type becomes embedded through EF.
        $stream = new StreamObject('attached bytes');
        $catalog = $this->catalog($this->entry('ACME_File', new IndirectReference($this->file($stream))));
        $revision = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
        $dictionary = $revision->binding($catalog)->dictionary ?? throw new \LogicException('A catalog has a dictionary.');
        $replacement = $dictionary->with($this->entry('ACME_Stream', new IndirectReference($stream)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filespec EF or RF');
        (new IncrementalUpdate($revision, new FileBody(new IndirectObject($replacement, $revision->binding($catalog)->identifier))))->toRevision();
    }

    public function testRelatedFilePairsCanReferToAnotherEmbeddedStream(): void
    {
        $file = $this->file(new F\EmbeddedFileStream('main'));
        $file = $file->with($this->entry('RF', new DictionaryType($this->entry('F', new ArrayType(new LiteralString('related.dat'), new IndirectReference(new F\EmbeddedFileStream('related')))))));
        $catalog = $this->catalog($this->entry('ACME_File', new IndirectReference($file)));
        self::assertStringContainsString('(related.dat)', (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf());
    }

    public function testRemovedAttachmentsDoNotRequireRewritingUnreachableHistoricalObjects(): void
    {
        $catalog = $this->catalog($this->entry('ACME_File', new IndirectReference($this->file(new F\EmbeddedFileStream('old attachment')))));
        $revision = (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toRevision();
        $dictionary = $revision->binding($catalog)->dictionary ?? throw new \LogicException('A catalog has a dictionary.');
        $next = (new IncrementalUpdate($revision, new FileBody(new IndirectObject($dictionary->without('ACME_File'), $revision->binding($catalog)->identifier))))->toRevision();
        self::assertStringStartsWith($revision->bytes, $next->bytes);
    }

    public function testFileAttachmentRequiresAnActualEmbeddedStream(): void
    {
        $file = new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('external.dat')));
        $annotation = new DictionaryType($this->entry('Type', new NameType('Annot')), $this->entry('Subtype', new NameType('FileAttachment')), $this->entry('Rect', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0))), $this->entry('FS', new IndirectReference($file)));
        $page = new PageObject($this->box(), new ResourceDictionary(), null, $this->entry('Annots', new ArrayType(new IndirectReference($annotation))));
        $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FileAttachment');
        (new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->toPdf();
    }

    public static function formats(): iterable
    {
        foreach (CrossReferenceFormat::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    private function file(StreamObject $stream): DictionaryType
    {
        return new DictionaryType($this->entry('Type', new NameType('Filespec')), $this->entry('F', new LiteralString('attached.dat')), $this->entry('EF', new DictionaryType($this->entry('F', new IndirectReference($stream)))));
    }

    private function catalog(Entry ...$entries): DocumentCatalog
    {
        return new DocumentCatalog((new PageTreeNode())->addKid(new PageObject($this->box(), new ResourceDictionary())), ...$entries);
    }

    private function box(): Rectangle
    {
        return new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100));
    }

    private function entry(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}
