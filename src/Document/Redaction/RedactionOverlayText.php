<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Document\Fonts\CMaps\CMapCode;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\ResourceDictionary;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ClipPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\EndPath;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Native simple-font replacement layout, with exact declared widths and explicit encoding, Q and Repeat. */
final readonly class RedactionOverlayText
{
    public function __construct(private RedactionLimits $limits)
    {
    }

    public function form(RedactionAnnotation $annotation, DictionaryType $defaultResources): StreamObject
    {
        $appearance = $annotation->defaultAppearance ?? throw new \InvalidArgumentException('Text replacement requires a default appearance.');
        $text = (new TextStringDecoder(PdfVersion::PDF20))->decode($annotation->overlayText ?? throw new \InvalidArgumentException('Text replacement requires OverlayText.'));
        if (strlen($text) > $this->limits->bytes) {
            throw new \OverflowException('Replacement text exceeds its explicit byte budget.');
        }
        $appearance->validateResources($defaultResources);
        $selection = null;
        $style = [];
        foreach ($appearance->operators as $operator) {
            if ($operator instanceof SetTextFontAndSize) {
                $selection = $operator;
            } elseif (in_array($operator->symbol(), ['G', 'g', 'RG', 'rg', 'K', 'k'], true)) {
                $style[] = $operator;
            } else {
                throw new \InvalidArgumentException('Normalize replacement DA to Tf and explicit device colors; unsupported state is never silently discarded.');
            }
        }
        if (!$selection instanceof SetTextFontAndSize) {
            throw new \InvalidArgumentException('Replacement text requires one effective Tf selection.');
        }
        $fonts = $this->value($defaultResources->get('Font'));
        $font = $fonts instanceof DictionaryType ? $this->value($fonts->get($selection->font)) : null;
        if (!$font instanceof DictionaryType) {
            throw new \InvalidArgumentException('Replacement font is absent from explicit default resources.');
        }
        $subtype = $this->value($font->get('Subtype'));
        if (!$subtype instanceof NameType || !in_array($subtype->value, ['Type1', 'MMType1', 'TrueType'], true)) {
            throw new \InvalidArgumentException('Native replacement text requires a normalized simple font; use RO for complex shaping.');
        }
        $bytes = '';
        $units = 0.0;
        $first = $this->value($font->get('FirstChar'));
        $widths = $this->value($font->get('Widths'));
        $unicode = $this->value($font->get('ToUnicode'));
        $reverse = [];
        if ($unicode instanceof ToUnicodeCMap) {
            for ($code = 0; $code <= 255; ++$code) {
                $sequence = $unicode->sequence(new CMapCode(new HexadecimalString(chr($code))));
                if ($sequence !== null) {
                    $reverse[$sequence->utf8()] ??= chr($code);
                }
            }
        }
        $encoding = $this->value($font->get('Encoding'));
        $base = $this->value($font->get('BaseFont'));
        $ascii = $base instanceof NameType && in_array($base->value, ['Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique', 'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique', 'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic'], true) && ($encoding === null || ($encoding instanceof NameType && in_array($encoding->value, ['WinAnsiEncoding', 'MacRomanEncoding', 'StandardEncoding'], true)));
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \InvalidArgumentException('Replacement text is valid UTF-8.');
        }
        foreach ($characters as $character) {
            $byte = $reverse[$character] ?? ($ascii && strlen($character) === 1 && ord($character) >= 32 && ord($character) <= 126 ? $character : null);
            if ($byte === null) {
                throw new \InvalidArgumentException('Replacement text has no explicit one-byte glyph encoding; provide a shaped RO appearance.');
            }
            $width = $first instanceof IntegerType && $widths instanceof ArrayType ? $this->value($widths->items[ord($byte) - $first->integer()] ?? null) : null;
            if (!$width instanceof NumericType) {
                // The standard Courier family has a normative fixed 600-unit advance.
                if (!$base instanceof NameType || !str_starts_with($base->value, 'Courier')) {
                    throw new \InvalidArgumentException('Replacement layout requires explicit glyph widths or standard Courier metrics.');
                }
                $width = new NumericType(600);
            }
            $units += (float) $width->value;
            $bytes .= $byte;
        }
        $width = $annotation->rectangle->right - $annotation->rectangle->left;
        $height = $annotation->rectangle->top - $annotation->rectangle->bottom;
        if ($width <= 0 || $height <= 0 || $units < 0) {
            throw new \InvalidArgumentException('Replacement text requires a positive-area rectangle and nonnegative text advance.');
        }
        $size = (float) $selection->size->value;
        if ($size === 0.0) {
            $size = min($height * 0.8, $units > 0 ? $width * 1000 / $units : $height * 0.8);
        }
        $advance = $units * $size / 1000;
        $operators = [];
        foreach ($annotation->regions as $region) {
            $operators[] = new AppendRectangle(new NumericType($region->left - $annotation->rectangle->left), new NumericType($region->bottom - $annotation->rectangle->bottom), new NumericType($region->right - $region->left), new NumericType($region->top - $region->bottom));
        }
        array_push($operators, ...[new ClipPathNonzero(), new EndPath(), new BeginTextObject(), ...$style, new SetTextFontAndSize($selection->font, new NumericType($size))]);
        $copies = 0;
        $lineHeight = max($size * 1.2, 0.001);
        $tileWidth = max($advance, $size, 0.001);
        for ($y = max(0, $annotation->repeat ? $height - $size : ($height - $size) / 2); $y >= 0; $y -= $lineHeight) {
            $x = match ($annotation->quadding) {
                1 => ($width - $advance) / 2, 2 => $width - $advance, default => 0.0
            };
            if ($annotation->repeat) {
                while ($x > 0) {
                    $x -= $tileWidth;
                }
            }
            do {
                if (++$copies > $this->limits->operations) {
                    throw new \OverflowException('Repeated replacement text exceeds its explicit instruction budget.');
                }
                $operators[] = new SetTextMatrix(new Matrix(e: new NumericType($x), f: new NumericType($y)));
                $operators[] = new ShowText(new HexadecimalString($bytes));
                $x += $tileWidth;
            } while ($annotation->repeat && $x < $width);
            if (!$annotation->repeat) {
                break;
            }
        }
        $operators[] = new EndTextObject();
        $resources = new ResourceDictionary(new Entry(new NameType('Font'), new DictionaryType(new Entry($selection->font, new IndirectReference($font)))));
        return new StreamObject(new ContentStreams(...$operators), new DictionaryType(new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('Form')), new Entry(new NameType('BBox'), (new RedactionBounds(0, 0, $width, $height))->rectangle()), new Entry(new NameType('Resources'), $resources)));
    }

    private function value(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }
}
