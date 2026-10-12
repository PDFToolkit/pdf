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
        if (preg_match('/(?:^|[\x00\x09\x0A\x0C\x0D\x20])EI(?=[\x00\x09\x0A\x0C\x0D\x20()<>\[\]{}\/%]|$)/', $data) === 1) {
            throw new \InvalidArgumentException('Inline image data contain an ambiguous EI terminator; use an image XObject.');
        }
        $width = $dictionary->get('Width') ?? $dictionary->get('W');
        $height = $dictionary->get('Height') ?? $dictionary->get('H');
        foreach (['width' => $width, 'height' => $height] as $label => $value) {
            if (!$value instanceof NumericType || !is_int($value->value) || $value->value < 1) {
                throw new \InvalidArgumentException('Inline image ' . $label . ' must be a positive integer.');
            }
        }
        if (!$width instanceof NumericType || !$height instanceof NumericType) {
            throw new \InvalidArgumentException('Inline image dimensions must be numeric objects.');
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
            $rowBytes = (int) ceil($width->value * $components * $bits->value / 8);
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
