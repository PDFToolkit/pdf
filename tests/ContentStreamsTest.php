<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Graphics\GraphicsState\LineCapStyle;
use PDF\Graphics\GraphicsState\LineDashPattern;
use PDF\Graphics\GraphicsState\LineJoinStyle;
use PDF\Graphics\GraphicsState\RenderingIntent;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\ContentStreamsResources\Type3GlyphContentStream;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\StringType;
use PDF\Text\TextRenderingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentStreamsTest extends TestCase
{
    public function testEveryAnnexAOperatorHasAnImmutableObject(): void
    {
        $expected = ['b', 'B', 'b*', 'B*', 'BDC', 'BI', 'BMC', 'BT', 'BX', 'c', 'cm', 'CS', 'cs', 'd', 'd0', 'd1', 'Do', 'DP', 'EI', 'EMC', 'ET', 'EX', 'f', 'F', 'f*', 'G', 'g', 'gs', 'h', 'i', 'ID', 'j', 'J', 'K', 'k', 'l', 'm', 'M', 'MP', 'n', 'q', 'Q', 're', 'RG', 'rg', 'ri', 's', 'S', 'SC', 'sc', 'SCN', 'scn', 'sh', 'T*', 'Tc', 'Td', 'TD', 'Tf', 'Tj', 'TJ', 'TL', 'Tm', 'Tr', 'Ts', 'Tw', 'Tz', 'v', 'w', 'W', 'W*', 'y', "'", '"'];
        $actual = [];
        foreach (glob(dirname(__DIR__) . '/src/Syntax/ContentStreamsResources/Operators/*.php') as $file) {
            $class = 'PDF\\Syntax\\ContentStreamsResources\\Operators\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            self::assertTrue($reflection->isReadOnly(), $class);
            self::assertTrue($reflection->isSubclassOf(Operator::class), $class);
            if ($class === Op\InlineImage::class) {
                array_push($actual, 'BI', 'ID', 'EI');
            } else {
                $actual[] = $reflection->getConstant('SYMBOL');
            }
        }
        sort($expected);
        sort($actual);
        self::assertCount(73, $actual);
        self::assertSame($expected, $actual);
    }

    /** Every ordinary class is instantiated and compared to fixed operand tokens. */
    public function testAllOrdinaryInstructionsSerializeTheirTypedOperandsInOrder(): void
    {
        $count = 0;
        foreach (glob(dirname(__DIR__) . '/src/Syntax/ContentStreamsResources/Operators/*.php') as $file) {
            $class = 'PDF\\Syntax\\ContentStreamsResources\\Operators\\' . basename($file, '.php');
            if ($class === Op\InlineImage::class) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            $arguments = [];
            $tokens = [];
            foreach ($reflection->getConstructor()->getParameters() as $parameter) {
                $type = $parameter->getType();
                $typeName = $type instanceof \ReflectionUnionType
                    ? ($class === Op\BeginMarkedContentWithProperties::class || $class === Op\MarkedContentPointWithProperties::class ? NameType::class : NumericType::class)
                    : $type->getName();
                [$argument, $expected] = match ($typeName) {
                    NumericType::class => [new NumericType($parameter->getName() === 'wy' ? 0 : 1), $parameter->getName() === 'wy' ? '0' : '1'],
                    NameType::class => [new NameType('N'), '/N'],
                    StringType::class => [new LiteralString('sample'), '(sample)'],
                    ArrayType::class => [new ArrayType(new LiteralString('sample'), new NumericType(-50)), '[(sample) -50]'],
                    Matrix::class => [Matrix::identity(), '1 0 0 1 0 0'],
                    LineCapStyle::class => [LineCapStyle::Round, '1'],
                    LineJoinStyle::class => [LineJoinStyle::Bevel, '2'],
                    RenderingIntent::class => [RenderingIntent::Perceptual, '/Perceptual'],
                    LineDashPattern::class => [new LineDashPattern(new ArrayType(new NumericType(3), new NumericType(2)), new NumericType(1)), '[3 2] 1'],
                    TextRenderingMode::class => [TextRenderingMode::FillAndClip, '4'],
                    default => throw new \LogicException('Missing fixture for ' . $typeName),
                };
                $arguments[] = $argument;
                $tokens[] = $expected;
            }
            $operator = $reflection->newInstanceArgs($arguments);
            $tokens[] = $reflection->getConstant('SYMBOL');
            self::assertSame(implode(' ', $tokens), (string) $operator, $class);
            ++$count;
        }
        self::assertSame(70, $count);
    }

    public function testTextOperatorsEscapeBytesThroughTheStringOperand(): void
    {
        $object = new ContentStreams(
            new Op\BeginTextObject(),
            new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
            new Op\ShowText(new LiteralString('A (B) \\ C')),
            new Op\EndTextObject(),
        );
        self::assertSame("BT\n/F1 12 Tf\n(A \\(B\\) \\\\ C) Tj\nET\n", (string) $object);
    }

    public function testValidGraphicTextAndMarkedContentSequences(): void
    {
        $content = new ContentStreams(
            new Op\SaveGraphicsState(),
            new Op\SetNonstrokingRGBColor(new NumericType(1), new NumericType(0), new NumericType(0)),
            new Op\MoveTo(new NumericType(0), new NumericType(0)),
            new Op\LineTo(new NumericType(10), new NumericType(10)),
            new Op\ClipPathEvenOdd(),
            new Op\EndPath(),
            new Op\BeginMarkedContent(new NameType('Span')),
            new Op\BeginTextObject(),
            new Op\SetCharacterSpacing(new NumericType(1)),
            new Op\SetTextFontAndSize(new NameType('F1'), new NumericType(12)),
            new Op\ShowText(new LiteralString('Hello')),
            new Op\EndTextObject(),
            new Op\EndMarkedContent(),
            new Op\RestoreGraphicsState(),
        );
        self::assertSame("q\n1 0 0 rg\n0 0 m\n10 10 l\nW*\nn\n/Span BMC\nBT\n1 Tc\n/F1 12 Tf\n(Hello) Tj\nET\nEMC\nQ\n", (string) $content);
        self::assertCount(14, $content);
    }

    public function testAppendingCreatesAnotherContentStream(): void
    {
        $original = new ContentStreams(new Op\BeginCompatibilitySection());
        $finished = $original->with(new Op\EndCompatibilitySection());
        self::assertCount(1, $original);
        self::assertCount(2, $finished);
        self::assertSame("BX\nEX\n", (string) $finished);
    }

    /** @return iterable<string, array{ContentStreams}> */
    public static function invalidSequences(): iterable
    {
        yield 'unclosed text' => [new ContentStreams(new Op\BeginTextObject())];
        yield 'unmatched ET' => [new ContentStreams(new Op\EndTextObject())];
        yield 'nested text' => [new ContentStreams(new Op\BeginTextObject(), new Op\BeginTextObject(), new Op\EndTextObject(), new Op\EndTextObject())];
        yield 'text positioning outside text' => [new ContentStreams(new Op\MoveToNextTextLine())];
        yield 'showing outside text' => [new ContentStreams(new Op\ShowText(new LiteralString('x')))];
        yield 'path inside text' => [new ContentStreams(new Op\BeginTextObject(), new Op\MoveTo(new NumericType(0), new NumericType(0)), new Op\EndTextObject())];
        yield 'save inside text' => [new ContentStreams(new Op\BeginTextObject(), new Op\SaveGraphicsState(), new Op\RestoreGraphicsState(), new Op\EndTextObject())];
        yield 'matrix inside text' => [new ContentStreams(new Op\BeginTextObject(), new Op\ConcatenateMatrix(Matrix::identity()), new Op\EndTextObject())];
        yield 'line without a current point' => [new ContentStreams(new Op\LineTo(new NumericType(0), new NumericType(1)), new Op\StrokePath())];
        yield 'unfinished path' => [new ContentStreams(new Op\MoveTo(new NumericType(0), new NumericType(0)))];
        yield 'state operator inside path' => [new ContentStreams(new Op\MoveTo(new NumericType(0), new NumericType(0)), new Op\SetLineWidth(new NumericType(2)), new Op\StrokePath())];
        yield 'construction after clipping' => [new ContentStreams(new Op\MoveTo(new NumericType(0), new NumericType(0)), new Op\ClipPathNonzero(), new Op\LineTo(new NumericType(1), new NumericType(1)), new Op\EndPath())];
        yield 'unmatched restore' => [new ContentStreams(new Op\RestoreGraphicsState())];
        yield 'unclosed save' => [new ContentStreams(new Op\SaveGraphicsState())];
        yield 'unmatched marked content' => [new ContentStreams(new Op\EndMarkedContent())];
        yield 'unclosed marked content' => [new ContentStreams(new Op\BeginMarkedContent(new NameType('Span')))];
        yield 'unmatched compatibility' => [new ContentStreams(new Op\EndCompatibilitySection())];
        yield 'unclosed compatibility' => [new ContentStreams(new Op\BeginCompatibilitySection())];
        yield 'metrics in a page stream' => [new ContentStreams(new Op\SetGlyphWidth(new NumericType(10), new NumericType(0)))];
        yield 'missing glyph metrics' => [new Type3GlyphContentStream()];
        yield 'colours in an uncolored glyph' => [new Type3GlyphContentStream(new Op\SetGlyphWidthAndBoundingBox(new NumericType(10), new NumericType(0), new NumericType(0), new NumericType(0), new NumericType(10), new NumericType(10)), new Op\SetNonstrokingGray(new NumericType(0)))];
    }

    #[DataProvider('invalidSequences')]
    public function testInvalidOperatorContextsAreRejected(ContentStreams $content): void
    {
        $this->expectException(\LogicException::class);
        $content->validate();
    }

    public function testType3GlyphMetrics(): void
    {
        $content = new Type3GlyphContentStream(new Op\SetGlyphWidth(new NumericType(500), new NumericType(0)));
        self::assertSame("500 0 d0\n", (string) $content);
        self::assertInstanceOf(Type3GlyphContentStream::class, $content->with());
    }

    public function testTextAdjustmentArrayRejectsNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Op\ShowTextWithIndividualGlyphPositioning(new ArrayType(new NameType('invalid')));
    }

    public function testPatternNameMustBeLast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Op\SetStrokingColorWithPattern(new NameType('P1'), new NumericType(1));
    }

    public function testUncoloredPatternOperands(): void
    {
        $operator = new Op\SetNonstrokingColorWithPattern(new NumericType(1), new NumericType(0), new NumericType(0), new NameType('P1'));
        self::assertSame('1 0 0 /P1 scn', (string) $operator);
    }

    public function testPropertyListCannotContainIndirectReferences(): void
    {
        $operator = new Op\BeginMarkedContentWithProperties(new NameType('Span'), new DictionaryType(new Entry(new NameType('MCID'), new IndirectReference(new NumericType(1)))));
        $this->expectException(\InvalidArgumentException::class);
        (string) $operator;
    }

    public function testDashPatternRules(): void
    {
        self::assertSame('[] 0 d', (string) new Op\SetLineDashPattern(LineDashPattern::solid()));
        $this->expectException(\InvalidArgumentException::class);
        new LineDashPattern(new ArrayType(new NumericType(0), new NumericType(0)), new NumericType(0));
    }

    private static function imageDictionary(int $width = 1): DictionaryType
    {
        return new DictionaryType(
            new Entry(new NameType('W'), new NumericType($width)),
            new Entry(new NameType('H'), new NumericType(1)),
            new Entry(new NameType('BPC'), new NumericType(8)),
            new Entry(new NameType('CS'), new NameType('G')),
        );
    }

    public function testInlineImageWritesAllThreeDelimitersWithoutAlteringBinaryBytes(): void
    {
        $image = new Op\InlineImage(self::imageDictionary(), "\x00");
        self::assertSame("BI\n/W 1\n/H 1\n/BPC 8\n/CS /G\nID\n\x00\nEI", (string) $image);
        self::assertSame(['BI', 'ID', 'EI'], $image->symbols());
        self::assertSame((string) $image . "\n", (string) new ContentStreams($image));
    }

    public function testAmbiguousInlineImageTerminatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Op\InlineImage(self::imageDictionary(5), " EI\nx");
    }

    public function testInlineImageLengthMustMatchUnfilteredDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Op\InlineImage(self::imageDictionary(2), "\x00");
    }

    public function testInlineImageCannotBeginWithAnEndToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Op\InlineImage(self::imageDictionary(3), 'EI ');
    }

    public function testImageMaskCanOmitBitsAndColorSpace(): void
    {
        $dictionary = new DictionaryType(
            new Entry(new NameType('Width'), new NumericType(1)),
            new Entry(new NameType('Height'), new NumericType(1)),
            new Entry(new NameType('ImageMask'), new BooleanType(true)),
        );
        self::assertStringContainsString("ID\n\x80\nEI", (string) new Op\InlineImage($dictionary, "\x80"));
    }
}
