<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Intrinsic image rules; resource-name colour spaces stay document-context dependent. */
final readonly class ImageSamples implements DictionaryConstraint
{
    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $mask = $values->value($dictionary, 'ImageMask');
        $mask = $mask instanceof BooleanType && $mask->value;
        $filters = $values->names($dictionary, 'Filter');
        $jpx = in_array('JPXDecode', $filters, true);
        $bits = $values->value($dictionary, 'BitsPerComponent');
        $colour = $values->value($dictionary, 'ColorSpace');
        $decode = $values->numbers($dictionary, 'Decode');
        if ($mask) {
            if (($bits !== null && (!$bits instanceof IntegerType || $bits->integer() !== 1)) || $colour !== null || $values->value($dictionary, 'Mask') !== null) {
                throw new \InvalidArgumentException('An image mask forbids /ColorSpace and /Mask and permits only one-bit samples.');
            }
            if ($decode !== null && $decode != [0, 1] && $decode != [1, 0]) {
                throw new \InvalidArgumentException('An image mask /Decode shall be [0 1] or [1 0].');
            }
            return;
        }
        if (!$jpx && ($colour === null || !$bits instanceof IntegerType)) {
            throw new \InvalidArgumentException('A non-JPX image requires /ColorSpace and /BitsPerComponent.');
        }
        $colourName = $colour instanceof NameType ? $colour->value : (($colour instanceof ArrayType && ($colour->items[0] ?? null) instanceof NameType) ? $colour->items[0]->value : null);
        if ($colourName === 'Pattern') {
            throw new \InvalidArgumentException('An image cannot use a Pattern colour space.');
        }
        // JPX bit depth is determined by the encoded data, so the entry is ignored.
        if (!$jpx && $bits instanceof IntegerType) {
            if (!in_array($bits->integer(), [1, 2, 4, 8, 16], true)) {
                throw new \InvalidArgumentException('Invalid image /BitsPerComponent.');
            }
            foreach ($filters as $filter) {
                $expected = match ($filter) {
                    'CCITTFaxDecode', 'JBIG2Decode' => 1,
                    'RunLengthDecode', 'DCTDecode' => 8,
                    default => null,
                };
                if ($expected !== null && $bits->integer() !== $expected) {
                    throw new \InvalidArgumentException('/BitsPerComponent is inconsistent with /' . $filter . '.');
                }
            }
        }
        $components = match ($colourName) {
            'DeviceGray', 'CalGray', 'Indexed', 'Separation' => 1,
            'DeviceRGB', 'CalRGB', 'Lab' => 3,
            'DeviceCMYK' => 4,
            default => null,
        };
        if ($decode !== null && $components !== null && count($decode) !== 2 * $components) {
            throw new \InvalidArgumentException('/Decode length disagrees with the image colour components.');
        }
        $inData = $values->value($dictionary, 'SMaskInData');
        if ($jpx && $inData instanceof IntegerType && $inData->integer() !== 0 && $values->value($dictionary, 'SMask') !== null) {
            throw new \InvalidArgumentException('Nonzero /SMaskInData forbids /SMask.');
        }
    }
}
