<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\DocumentGraphValidation;
use PDF\FileStructure;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\DataStructure\PdfDocOrUtf16TextStringEncoding;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\DataStructure\Utf16BETextStringEncoding;
use PDF\Syntax\DataStructure\Utf8TextStringEncoding;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Version;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\IndirectObject\ObjectNumber;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;
use PHPUnit\Framework\TestCase;

/** ISO_32000-2_sponsored_EC3.pdf, 7.5.2/Table 29/7.9.2.2.1, PDF pp. 69–70/114/131–132. */
final class Pdf20TextStringTest extends TestCase
{
    private function catalog(Entry ...$entries): DocumentCatalog
    {
        return new DocumentCatalog(new PageTreeNode(), ...$entries);
    }

    private function entry(string $key, \PDF\Syntax\FileStructure\BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }

    private function rejected(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('The invalid value or document was accepted.');
        } catch (\InvalidArgumentException | \LogicException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    public function testPdf20HeaderAndCatalogAreExplicitVersionObjects(): void
    {
        self::assertSame(PdfVersion::PDF20, (new FileHeader('2.0'))->version);
        $entry = new Version(new NameType('2.0'));
        self::assertSame('Version', $entry->key()->value);
        self::assertSame('/2.0', (string) $entry->value());
        $pdf = (new FileStructure(new FileHeader(PdfVersion::PDF20), new FileBody($this->catalog())))->toPdf();
        self::assertStringStartsWith("%PDF-2.0\n%\xe2\xe3\xcf\xd3\n", $pdf);
        self::assertStringContainsString('startxref', $pdf);
        self::assertSame(PdfVersion::PDF17, (new FileHeader())->version);
    }

    public function testEffectiveVersionUsesTheLaterDirectDeclaration(): void
    {
        $pdf20 = $this->catalog(new Version(new NameType('2.0')));
        self::assertSame(PdfVersion::PDF20, (new EffectivePdfVersion(new FileHeader(PdfVersion::PDF17), $pdf20))->value);
        self::assertSame(PdfVersion::PDF20, (new EffectivePdfVersion(new FileHeader(PdfVersion::PDF20), $this->catalog(new Version(new NameType('1.7')))))->value);
        self::assertSame(PdfVersion::PDF20, (new EffectivePdfVersion(new FileHeader(PdfVersion::PDF20), $this->catalog()))->value);
        self::assertSame(PdfVersion::PDF17, (new EffectivePdfVersion(new FileHeader(PdfVersion::PDF17), $this->catalog(new Version(new NameType('1.4')))))->value);
    }

    public function testCatalogVersionDoesNotAcceptUnimplementedOrAmbiguousNames(): void
    {
        foreach (['1.8', '1.9', '2.1', '3.0', '2', '02.0', '2.00', "2.0\0"] as $value) {
            $this->rejected(fn () => new Version(new NameType($value)), $value === "2.0\0" ? 'null byte' : 'supported PDF version');
        }
    }

    public function testEffectiveCatalogVersionMustBeADirectSupportedName(): void
    {
        foreach ([new IntegerType(2), new NameType('2.1'), new IndirectReference(new NameType('2.0'))] as $value) {
            $this->rejected(fn () => new EffectivePdfVersion(new FileHeader(), new DictionaryType($this->entry('Version', $value))), 'direct supported PDF version');
        }
    }

    public function testCompatibleDefaultKeepsExistingPdfDocAndUtf16Representations(): void
    {
        self::assertSame('ASCII', (new TextString('ASCII'))->value);
        self::assertSame("\xe9", (new TextString('é'))->value);
        self::assertSame("\xfe\xff\x00\xe9\xd8\x3d\xde\x42", (new TextString('é🙂'))->value);
        self::assertInstanceOf(PdfDocOrUtf16TextStringEncoding::class, (new TextString('ASCII'))->encoding);
    }

    public function testDefaultAvoidsBothUnicodeBomCollisionsWithoutRequiringPdf20(): void
    {
        foreach (['þÿbeginning', 'ï»¿beginning'] as $text) {
            $string = new TextString($text);
            self::assertStringStartsWith("\xfe\xff", $string->value);
            self::assertSame($text, (new TextStringDecoder())->decode($string));
            self::assertSame($text, (new TextStringDecoder(PdfVersion::PDF20))->decode($string));
            $string->validateVersion(new EffectivePdfVersion(new FileHeader(PdfVersion::PDF17), $this->catalog()));
            $pdf = (new FileStructure(new FileHeader(), new FileBody($this->catalog($this->entry('Text', $string)))))->toPdf();
            self::assertStringStartsWith('%PDF-1.7', $pdf);
        }
    }

    public function testExplicitUtf16IncludesBomForAsciiAndSupplementaryScalars(): void
    {
        $encoding = new Utf16BETextStringEncoding();
        self::assertSame("\xfe\xff\x00A", (new TextString('A', $encoding))->value);
        self::assertSame("\xfe\xff\xd8\x3d\xde\x42", (new TextString('🙂', $encoding))->value);
        self::assertSame("\xfe\xff", (new TextString('', $encoding))->value);
        self::assertSame("\xfe\xff\xfe\xff\x00A", (new TextString("\xef\xbb\xbfA", $encoding))->value);
    }

    public function testExplicitUtf8IncludesOneEncodingBomAndKeepsApplicationText(): void
    {
        $encoding = new Utf8TextStringEncoding();
        foreach (['', 'ASCII', 'é🙂', "\xef\xbb\xbfA", "a\0b", "\x1benUS\x1bhello"] as $text) {
            $string = new TextString($text, $encoding);
            self::assertSame($encoding, $string->encoding);
            self::assertSame($text, $string->text);
            self::assertSame("\xef\xbb\xbf" . $text, $string->value);
            self::assertSame($text, (new TextStringDecoder(PdfVersion::PDF20))->decode($string));
        }
        self::assertSame('(\357\273\277)', (string) new TextString('', $encoding));
    }

    public function testEveryBuiltInStrategyRejectsInvalidApplicationUnicode(): void
    {
        foreach (["\xc0\xaf", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\x80", "\xf0\x9f"] as $text) {
            foreach ([new PdfDocOrUtf16TextStringEncoding(), new Utf16BETextStringEncoding(), new Utf8TextStringEncoding()] as $encoding) {
                $this->rejected(fn () => new TextString($text, $encoding), 'valid UTF-8');
                $this->rejected(fn () => $encoding->encode($text), 'valid UTF-8');
            }
        }
    }

    public function testPdf20DecoderUsesTheUtf8BomForBothStringSyntaxes(): void
    {
        $decoder = new TextStringDecoder(PdfVersion::PDF20);
        foreach ([new LiteralString("\xef\xbb\xbfé🙂"), new HexadecimalString("\xef\xbb\xbfé🙂")] as $string) {
            self::assertSame('é🙂', $decoder->decode($string));
        }
        self::assertSame('é', $decoder->decode(new LiteralString("\xe9")));
        self::assertSame('🙂', $decoder->decode(new LiteralString("\xfe\xff\xd8\x3d\xde\x42")));
        self::assertSame("\xef\xbb\xbfA", $decoder->decode(new LiteralString("\xef\xbb\xbf\xef\xbb\xbfA")));
    }

    public function testPdf20DecoderRejectsMalformedUtf8AfterItsBom(): void
    {
        $decoder = new TextStringDecoder(PdfVersion::PDF20);
        foreach (["\xc0\xaf", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\x80", "\xf0\x9f", "\xe2\x28\xa1", "\xc2"] as $bytes) {
            $this->rejected(fn () => $decoder->decode(new LiteralString("\xef\xbb\xbf" . $bytes)), 'valid Unicode scalar values');
        }
    }

    public function testPdf17DecoderKeepsHistoricalPdfDocInterpretationOfUtf8BomBytes(): void
    {
        $bytes = new LiteralString("\xef\xbb\xbf\xc0\xaf");
        self::assertSame('ï»¿À¯', (new TextStringDecoder())->decode($bytes));
        self::assertSame('ï»¿À¯', (new TextStringDecoder(PdfVersion::PDF17))->decode($bytes));
        self::assertSame(PdfVersion::PDF17, (new TextStringDecoder())->version);
    }

    public function testUtf16DecoderStillRejectsIncompleteOrUnpairedSurrogates(): void
    {
        foreach ([PdfVersion::PDF17, PdfVersion::PDF20] as $version) {
            $decoder = new TextStringDecoder($version);
            $this->rejected(fn () => $decoder->decode(new LiteralString("\xfe\xff\0")), 'complete two-byte code units');
            foreach (["\xd8\0", "\xdc\0", "\xd8\0\0A", "\xdc\0\xd8\0"] as $bytes) {
                $this->rejected(fn () => $decoder->decode(new LiteralString("\xfe\xff" . $bytes)), 'valid paired surrogates');
            }
        }
    }

    public function testSameUtf8ObjectIsValidatedIndependentlyForEachDocument(): void
    {
        $text = new TextString('é🙂', new Utf8TextStringEncoding());
        $catalog = $this->catalog($this->entry('Text', new ArrayType($text)));
        $pdf20 = new FileStructure(new FileHeader(PdfVersion::PDF20), new FileBody($catalog));
        $first = $pdf20->toPdf();
        $this->rejected(fn () => (new FileStructure(new FileHeader(PdfVersion::PDF17), new FileBody($catalog)))->toPdf(), 'requires PDF 2.0');
        self::assertSame($first, $pdf20->toPdf());
        self::assertSame("\xef\xbb\xbfé🙂", $text->value);
    }

    public function testCatalogUpgradeAllowsUtf8WithoutChangingTheHeader(): void
    {
        $catalog = $this->catalog(new Version(new NameType('2.0')), $this->entry('Text', new TextString('é🙂', new Utf8TextStringEncoding())));
        $pdf = (new FileStructure(new FileHeader(PdfVersion::PDF17), new FileBody($catalog)))->toPdf();
        self::assertStringStartsWith('%PDF-1.7', $pdf);
        self::assertStringContainsString('/Version /2.0', $pdf);
        self::assertStringContainsString('\357\273\277', $pdf);
    }

    public function testUtf8IndirectObjectsAreAlsoRejectedInPdf17(): void
    {
        $text = new TextString('Unicode', new Utf8TextStringEncoding());
        $catalog = $this->catalog($this->entry('Text', new IndirectReference($text)));
        $this->rejected(fn () => (new FileStructure(new FileHeader(), new FileBody($catalog)))->toPdf(), 'requires PDF 2.0');
    }

    public function testBinaryStringsAreNotMistakenForUtf8Text(): void
    {
        $bytes = new LiteralString("\xef\xbb\xbf\xff\0");
        $pdf = (new FileStructure(new FileHeader(), new FileBody($this->catalog($this->entry('Bytes', $bytes)))))->toPdf();
        self::assertStringStartsWith('%PDF-1.7', $pdf);
        self::assertStringContainsString('\357\273\277\377\000', $pdf);
    }

    public function testIncrementalCatalogUpgradeCanIntroduceUtf8Text(): void
    {
        foreach ([CrossReferenceFormat::Table, CrossReferenceFormat::Stream] as $format) {
            $catalog = $this->catalog();
            $base = (new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
            $text = new TextString('é🙂', new Utf8TextStringEncoding());
            $changed = $catalog->with(new Version(new NameType('2.0')))->with($this->entry('Text', new IndirectReference($text)));
            $next = (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $base->binding($catalog)->identifier))))->toRevision();
            self::assertStringStartsWith($base->bytes, $next->bytes);
            self::assertStringContainsString('/Version /2.0', substr($next->bytes, strlen($base->bytes)));
            self::assertSame($text, $next->binding($text)->value);
            self::assertSame(PdfVersion::PDF17, $next->header->version);
        }
    }

    public function testIncrementalUtf8TextWithoutVersionUpgradeIsRejected(): void
    {
        $base = (new FileStructure(new FileHeader(), new FileBody($this->catalog())))->toRevision();
        $this->rejected(fn () => (new IncrementalUpdate($base, new FileBody(new TextString('Unicode', new Utf8TextStringEncoding()))))->toRevision(), 'requires PDF 2.0');
    }

    public function testHistoricalIndirectUtf8StillPreventsRemovingTheCatalogUpgrade(): void
    {
        foreach ([CrossReferenceFormat::Table, CrossReferenceFormat::Stream] as $format) {
            $text = new TextString('Unicode', new Utf8TextStringEncoding());
            $catalog = $this->catalog(new Version(new NameType('2.0')), $this->entry('Text', new IndirectReference($text)));
            $base = (new FileStructure(new FileHeader(), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
            $changed = $catalog->without('Version');
            $this->rejected(fn () => (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $base->binding($catalog)->identifier))))->toRevision(), 'cannot lower the effective version');
            self::assertSame($text, $base->binding($text)->snapshot);
        }
    }

    public function testHistoricalDirectUtf8UsesTheFrozenDictionaryContents(): void
    {
        $text = new TextString('Unicode', new Utf8TextStringEncoding());
        $catalog = $this->catalog(new Version(new NameType('2.0')), $this->entry('Text', $text));
        $base = (new FileStructure(new FileHeader(), new FileBody($catalog)))->toRevision();
        $changed = $catalog->without('Version');
        $this->rejected(fn () => (new IncrementalUpdate($base, new FileBody(new IndirectObject($changed, $base->binding($catalog)->identifier))))->toRevision(), 'cannot lower the effective version');
        self::assertSame($text, $base->binding($catalog)->dictionary?->get('Text'));
    }

    public function testImmutableHistoricalIndirectUtf8IsCheckedInTheProjectedGraph(): void
    {
        $text = new TextString('Unicode', new Utf8TextStringEncoding());
        $catalog = $this->catalog(new Version(new NameType('2.0')), $this->entry('Text', new IndirectReference($text)));
        $base = (new FileStructure(new FileHeader(), new FileBody($catalog)))->toRevision();
        $context = new SerializationContext(new ObjectNumber($base->size->integer()), ...$base->objects());
        $graph = new DocumentGraphValidation($context, $catalog->without('Version'), ...$base->objects());
        $this->rejected(fn () => $graph->validate(new FileHeader()), 'requires PDF 2.0');
        self::assertSame($text, $base->binding($text)->snapshot);
    }
}
