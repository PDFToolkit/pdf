<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources\Operators;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/**
 * BI, ID and EI form one indivisible instruction (ISO 32000-1, 8.9.7).
 *
 * Data are encoded bytes matching the dictionary's filters. Binary payloads
 * containing an ambiguous EI token must instead be stored in an image XObject.
 */
final readonly class InlineImage extends Operator
{
    public const string SYMBOL = 'BI';

    public function __construct(public DictionaryType $dictionary, public string $data)
    {
        self::assertDirect($dictionary);
        // Some readers accept EI without preceding whitespace. Reject that
        // ambiguity too, so binary image data cannot become content operators.
        if (preg_match('/EI(?=[\x00\x09\x0A\x0C\x0D\x20()<>\[\]{}\/%]|$)/', $data) === 1) {
            throw new \InvalidArgumentException('Inline image data contain an ambiguous EI terminator; use an image XObject.');
        }
        $width = $dictionary->get('Width') ?? $dictionary->get('W');
        $height = $dictionary->get('Height') ?? $dictionary->get('H');
        if (!$width instanceof NumericType || !is_int($width->value) || $width->value < 1) {
            throw new \InvalidArgumentException('Inline image width must be a positive integer.');
        }
        if (!$height instanceof NumericType || !is_int($height->value) || $height->value < 1) {
            throw new \InvalidArgumentException('Inline image height must be a positive integer.');
        }
        $mask = $dictionary->get('ImageMask') ?? $dictionary->get('IM');
        if ($mask !== null && !$mask instanceof BooleanType) {
            throw new \InvalidArgumentException('Inline image ImageMask must be a boolean.');
        }
        $isMask = $mask instanceof BooleanType && $mask->value;
        $bits = $dictionary->get('BitsPerComponent') ?? $dictionary->get('BPC');
        if ($isMask && $bits === null) {
            $bits = new NumericType(1);
        }
        if (!$bits instanceof NumericType || !is_int($bits->value) || !in_array($bits->value, [1, 2, 4, 8, 16], true)) {
            throw new \InvalidArgumentException('Inline image BitsPerComponent must be 1, 2, 4, 8 or 16.');
        }
        if ($isMask && $bits->value !== 1) {
            throw new \InvalidArgumentException('An image mask has one bit per component.');
        }
        $colorSpace = $dictionary->get('ColorSpace') ?? $dictionary->get('CS');
        if (!$isMask && !$colorSpace instanceof NameType && !$colorSpace instanceof ArrayType) {
            throw new \InvalidArgumentException('An inline image requires a name or array ColorSpace.');
        }
        if ($colorSpace instanceof NameType && $colorSpace->value === 'Pattern') {
            throw new \InvalidArgumentException('Pattern is not an image colour space.');
        }
        $components = $isMask ? 1 : ($colorSpace instanceof NameType ? match ($colorSpace->value) {
            'DeviceGray', 'G' => 1,
            'DeviceRGB', 'RGB' => 3,
            'DeviceCMYK', 'CMYK' => 4,
            default => null,
        } : null);
        if ($components !== null && !$dictionary->has('Filter') && !$dictionary->has('F')) {
            $bitsPerPixel = $components * $bits->value;
            if ($width->value > intdiv(PHP_INT_MAX, $bitsPerPixel)) {
                throw new \OverflowException('Inline image row size exceeds the integer range.');
            }
            $rowBits = $width->value * $bitsPerPixel;
            $rowBytes = intdiv($rowBits, 8) + ($rowBits % 8 === 0 ? 0 : 1);
            if ($height->value > intdiv(PHP_INT_MAX, $rowBytes)) {
                throw new \OverflowException('Inline image byte count exceeds the integer range.');
            }
            if (strlen($data) !== $rowBytes * $height->value) {
                throw new \InvalidArgumentException('Inline image bytes do not match the unfiltered image dimensions.');
            }
        }
        parent::__construct();
    }

    /** @return list<string> */
    public function symbols(): array
    {
        return ['BI', 'ID', 'EI'];
    }

    public function toPdf(SerializationContext $context): string
    {
        self::assertDirect($this->dictionary);
        $lines = ['BI'];
        foreach ($this->dictionary->entries() as $entry) {
            $lines[] = $context->serialize($entry->key) . ' ' . $context->serialize($entry->value);
        }
        return implode("\n", $lines) . "\nID\n" . $this->data . "\nEI";
    }
}
