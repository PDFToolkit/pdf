<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Filters\EncodedData;

/** Copies only render-resource syntax, excluding ancillary metadata and unknown extension channels. */
final class RedactionResourceCopy
{
    /** @var \SplObjectStorage<BasicType, BasicType> */
    private \SplObjectStorage $copies;
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $active;
    private int $objects = 0;
    private int $bytes = 0;
    /** @var \SplObjectStorage<BasicType, null> */
    private \SplObjectStorage $sanitizedPrograms;

    /** @param ?\SplObjectStorage<BasicType, null> $excluded */
    public function __construct(private readonly RedactionLimits $limits, private readonly ?RedactionResourceSanitizer $sanitizer = null, private readonly ?\SplObjectStorage $excluded = null)
    {
        $this->copies = new \SplObjectStorage();
        $this->active = new \SplObjectStorage();
        $this->sanitizedPrograms = new \SplObjectStorage();
    }

    public function font(BasicType $object): BasicType
    {
        $font = $object instanceof IndirectReference ? $object->target() : $object;
        if (!$font instanceof DictionaryType) {
            throw new \InvalidArgumentException('A retained font resource is a dictionary.');
        }
        $descriptor = $font->get('FontDescriptor');
        $descriptor = $descriptor instanceof IndirectReference ? $descriptor->target() : $descriptor;
        $subtype = $font->get('Subtype');
        $requiresSanitizer = $subtype instanceof NameType && in_array($subtype->value, ['Type0', 'Type3'], true);
        foreach (['FontFile', 'FontFile2', 'FontFile3'] as $key) {
            $requiresSanitizer = $requiresSanitizer || ($descriptor instanceof DictionaryType && $descriptor->has($key));
        }
        if ($requiresSanitizer) {
            $font = $this->sanitizer?->font($font) ?? throw new \InvalidArgumentException('Retained embedded/complex fonts require an explicit font-program sanitizer; declared FontBBox and opaque programs are not sanitization.');
            $queue = [$font];
            $seen = new \SplObjectStorage();
            while ($queue !== []) {
                $value = array_pop($queue);
                if ($seen->offsetExists($value)) {
                    continue;
                }
                $seen->offsetSet($value);
                if (count($seen) > $this->limits->objects) {
                    throw new \OverflowException('Sanitized font resources exceed their explicit object budget.');
                }
                if ($value instanceof IndirectReference) {
                    $queue[] = $value->target();
                } elseif ($value instanceof DictionaryType) {
                    foreach ($value->entries() as $entry) {
                        if (in_array($entry->key->value, ['FontFile', 'FontFile2', 'FontFile3'], true)) {
                            $program = $entry->value instanceof IndirectReference ? $entry->value->target() : $entry->value;
                            $this->sanitizedPrograms->offsetSet($program);
                        }
                        $queue[] = $entry->value;
                    }
                } elseif ($value instanceof ArrayType) {
                    array_push($queue, ...$value->items);
                }
            }
        }
        $this->dictionaryKeys($font, ['Type', 'Subtype', 'BaseFont', 'FirstChar', 'LastChar', 'Widths', 'FontDescriptor', 'Encoding', 'ToUnicode', 'DescendantFonts', 'CIDSystemInfo', 'DW', 'W', 'DW2', 'W2', 'CIDToGIDMap', 'FontMatrix', 'FontBBox', 'CharProcs', 'Resources']);
        // A render-only rebuilt document deliberately discards glyph-to-Unicode
        // alternative strings, including multi-codepoint replacements for deleted glyphs.
        $copy = $this->copy($font);
        return $object instanceof IndirectReference ? new IndirectReference($copy) : $copy;
    }

    public function group(BasicType $object): BasicType
    {
        $group = $object instanceof IndirectReference ? $object->target() : $object;
        if (!$group instanceof DictionaryType) {
            throw new \InvalidArgumentException('A retained Form group requires a normalized transparency dictionary.');
        }
        $this->dictionaryKeys($group, ['Type', 'S', 'CS', 'I', 'K']);
        $kind = $group->get('S');
        if (!$kind instanceof NameType || $kind->value !== 'Transparency') {
            throw new \InvalidArgumentException('A retained Form group must explicitly select Transparency.');
        }
        foreach (['I', 'K'] as $key) {
            $value = $group->get($key);
            if ($value !== null && !$value instanceof BooleanType) {
                throw new \InvalidArgumentException('Retained transparency group flags must be direct booleans.');
            }
        }
        return $this->copy($object);
    }

    public function inlineImage(DictionaryType $dictionary): DictionaryType
    {
        $this->dictionaryKeys($dictionary, ['Width', 'Height', 'ColorSpace', 'BitsPerComponent', 'ImageMask', 'Decode', 'Interpolate', 'W', 'H', 'CS', 'BPC', 'IM', 'D', 'I']);
        $copy = $this->copy($dictionary);
        return $copy instanceof DictionaryType ? $copy : throw new \LogicException('A copied inline image dictionary remains a dictionary.');
    }

    public function copy(BasicType $object, int $depth = 0): BasicType
    {
        if ($this->excluded?->offsetExists($object)) {
            throw new \InvalidArgumentException('An affected original resource cannot be reintroduced through a retained dependency or cached copy.');
        }
        if ($this->copies->offsetExists($object)) {
            return $this->copies[$object];
        }
        if ($this->active->offsetExists($object)) {
            throw new \InvalidArgumentException('A retained redaction resource contains a cycle.');
        }
        if (++$this->objects > $this->limits->objects || $depth > $this->limits->depth) {
            throw new \OverflowException('Retained redaction resources exceed their explicit graph budget.');
        }
        $this->active->offsetSet($object);
        $source = $object;
        try {
            if ($object instanceof IndirectReference) {
                $copy = new IndirectReference($this->copy($object->target(), $depth + 1));
            } elseif ($object instanceof StreamObject) {
                $subtype = $object->dictionary()->get('Subtype');
                if (!($subtype instanceof NameType && $subtype->value === 'Image') && !$this->sanitizedPrograms->offsetExists($object)) {
                    throw new \InvalidArgumentException('An opaque retained resource stream was not normalized as image pixels or rebuilt by the explicit font-program sanitizer.');
                }
                if ($subtype instanceof NameType && $subtype->value === 'Image') {
                    try {
                        $this->pixels($object);
                    } catch (\InvalidArgumentException $error) {
                        $object = $this->sanitizer?->image($object) ?? throw $error;
                        $this->pixels($object);
                    }
                }
                if (!is_string($object->data)) {
                    throw new \InvalidArgumentException('Form content must pass the redaction content processor, not a generic resource copy.');
                }
                $this->bytes += strlen($object->data);
                if ($this->bytes > $this->limits->bytes) {
                    throw new \OverflowException('Retained redaction stream bytes exceed their explicit budget.');
                }
                $dictionary = $this->copy($object->dictionary(), $depth + 1);
                if (!$dictionary instanceof DictionaryType) {
                    throw new \LogicException('A copied stream dictionary remains a dictionary.');
                }
                $copy = new StreamObject($object->data, $dictionary, ...$object->filters);
            } elseif ($object instanceof ArrayType) {
                $copy = new ArrayType(...array_map(fn (BasicType $item): BasicType => $this->copy($item, $depth + 1), $object->items));
            } elseif ($object instanceof DictionaryType) {
                $entries = [];
                foreach ($object->entries() as $entry) {
                    if (in_array($entry->key->value, ['Metadata', 'PieceInfo', 'LastModified', 'StructParent', 'StructParents', 'AF', 'OPI', 'Alternates', 'Name', 'Intent', 'ToUnicode', 'CharSet', 'CIDSet', 'FontFamily', 'FontStretch', 'FontWeight', 'Style', 'Panose', 'FD'], true)) {
                        continue;
                    }
                    if (in_array($entry->key->value, ['FontFile', 'FontFile2', 'FontFile3'], true)) {
                        $program = $entry->value instanceof IndirectReference ? $entry->value->target() : $entry->value;
                        if (!$this->sanitizedPrograms->offsetExists($program)) {
                            throw new \InvalidArgumentException('An opaque font program was not rebuilt by the explicitly selected sanitizer.');
                        }
                    }
                    if (!in_array($entry->key->value, [
                        'Type', 'Subtype', 'BaseFont', 'FirstChar', 'LastChar', 'Widths', 'FontDescriptor', 'Encoding', 'BaseEncoding', 'Differences', 'ToUnicode', 'DescendantFonts', 'CIDSystemInfo', 'Registry', 'Ordering', 'Supplement', 'DW', 'W', 'DW2', 'W2', 'CIDToGIDMap',
                        'FontName', 'Flags', 'FontBBox', 'ItalicAngle', 'Ascent', 'Descent', 'Leading', 'CapHeight', 'XHeight', 'StemV', 'StemH', 'AvgWidth', 'MaxWidth', 'MissingWidth', 'FontWeight', 'FontStretch', 'FontFile', 'FontFile2', 'FontFile3', 'Length1', 'Length2', 'Length3', 'CharSet', 'CIDSet', 'FD', 'Style', 'Panose',
                        'Width', 'Height', 'ColorSpace', 'BitsPerComponent', 'ImageMask', 'Decode', 'Interpolate', 'Mask', 'SMask', 'SMaskInData', 'Matte', 'N', 'Alternate', 'Range', 'WhitePoint', 'BlackPoint', 'Gamma', 'Matrix', 'S', 'I', 'K', 'CS', 'UseCMap', 'WMode', 'CMapName', 'H', 'BPC', 'IM', 'D', 'DP', 'F', 'Filter', 'DecodeParms',
                    ], true)) {
                        throw new \InvalidArgumentException('Unsupported retained rendering-resource entry /' . $entry->key->value . '; no output was produced.');
                    }
                    $this->renderValue($entry->key->value, $entry->value);
                    $entries[] = new Entry(new NameType($entry->key->value), $this->copy($entry->value, $depth + 1));
                }
                $copy = new DictionaryType(...$entries);
            } else {
                if (!$object instanceof NameType && !$object instanceof NumericType && !$object instanceof StringType && !$object instanceof BooleanType && !$object instanceof NullType) {
                    throw new \InvalidArgumentException('A retained resource contains an unsupported normalized PDF value.');
                }
                $copy = $object; // Atomic PDF values carry no reachable old document graph.
            }
            $this->copies[$source] = $copy;
            return $copy;
        } finally {
            $this->active->offsetUnset($source);
        }
    }

    /** Entry names alone are not proof: malformed values must not become metadata channels. */
    private function renderValue(string $name, BasicType $source): void
    {
        $value = $source instanceof IndirectReference ? $source->target() : $source;
        if ($value instanceof NullType) {
            return;
        }
        $valid = match ($name) {
            'Type', 'Subtype', 'BaseFont', 'FontName', 'BaseEncoding', 'CMapName', 'S' => $value instanceof NameType,
            'Registry', 'Ordering' => $value instanceof StringType,
            'FirstChar', 'LastChar', 'Flags', 'Supplement', 'Width', 'Height', 'BitsPerComponent', 'N', 'Length1', 'Length2', 'Length3', 'WMode', 'H', 'BPC', 'SMaskInData' => $value instanceof NumericType && is_int($value->value),
            'DW', 'ItalicAngle', 'Ascent', 'Descent', 'Leading', 'CapHeight', 'XHeight', 'StemV', 'StemH', 'AvgWidth', 'MaxWidth', 'MissingWidth' => $value instanceof NumericType && is_finite((float) $value->value),
            'ImageMask', 'Interpolate', 'IM', 'I' => $value instanceof BooleanType,
            'K' => $value instanceof BooleanType || ($value instanceof NumericType && is_int($value->value)),
            'FontDescriptor', 'CIDSystemInfo' => $value instanceof DictionaryType,
            'FontFile', 'FontFile2', 'FontFile3' => $value instanceof StreamObject,
            'Encoding' => $value instanceof NameType || $value instanceof DictionaryType || $value instanceof StreamObject,
            'CIDToGIDMap', 'UseCMap' => $value instanceof NameType || $value instanceof StreamObject,
            'ColorSpace', 'CS' => $value instanceof NameType && in_array($value->value, ['DeviceGray', 'DeviceRGB', 'DeviceCMYK', 'G', 'RGB', 'CMYK'], true),
            'Mask' => $value instanceof StreamObject || $value instanceof ArrayType,
            'SMask' => $value instanceof StreamObject,
            'Filter', 'F' => $value instanceof NameType || $value instanceof ArrayType,
            'DecodeParms', 'DP' => $value instanceof DictionaryType || $value instanceof ArrayType,
            'Gamma' => $value instanceof NumericType || $value instanceof ArrayType,
            'Widths', 'W2', 'DW2', 'FontBBox', 'Decode', 'Matte', 'Range', 'WhitePoint', 'BlackPoint', 'Matrix', 'D', 'Differences', 'DescendantFonts' => $value instanceof ArrayType,
            'W' => $value instanceof ArrayType || ($value instanceof NumericType && is_int($value->value)),
            'Alternate' => $value instanceof NameType || $value instanceof ArrayType,
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException('A retained rendering-resource /' . $name . ' value has an unsafe or malformed type.');
        }
        if (!$value instanceof ArrayType) {
            if ($value instanceof DictionaryType) {
                $keys = match ($name) {
                    'FontDescriptor' => ['Type', 'FontName', 'Flags', 'FontBBox', 'ItalicAngle', 'Ascent', 'Descent', 'Leading', 'CapHeight', 'XHeight', 'StemV', 'StemH', 'AvgWidth', 'MaxWidth', 'MissingWidth', 'FontFile', 'FontFile2', 'FontFile3', 'CharSet', 'CIDSet', 'FontFamily', 'FontStretch', 'FontWeight', 'Style', 'Panose', 'FD'],
                    'CIDSystemInfo' => ['Registry', 'Ordering', 'Supplement'],
                    'Encoding' => ['Type', 'BaseEncoding', 'Differences'],
                    default => null,
                };
                if ($keys !== null) {
                    $this->dictionaryKeys($value, $keys);
                }
            }
            return;
        }
        if (in_array($name, ['Widths', 'W', 'W2', 'DW2', 'FontBBox', 'Decode', 'Matte', 'Range', 'WhitePoint', 'BlackPoint', 'Matrix', 'D', 'Gamma', 'Mask'], true)) {
            $expected = match ($name) {
                'FontBBox' => 4, 'Matrix' => 6, 'WhitePoint', 'BlackPoint' => 3, 'DW2' => 2, default => null
            };
            if ($expected !== null && count($value) !== $expected) {
                throw new \InvalidArgumentException('A retained rendering-resource numeric array has invalid dimensions.');
            }
            $this->numericValues($value, in_array($name, ['W', 'W2'], true));
        } elseif ($name === 'Differences') {
            $code = null;
            foreach ($value->items as $item) {
                $item = $item instanceof IndirectReference ? $item->target() : $item;
                if ($item instanceof NumericType && is_int($item->value) && $item->value >= 0 && $item->value <= 255) {
                    $code = $item->value;
                } elseif ($item instanceof NameType && $code !== null && $code <= 255) {
                    ++$code;
                } else {
                    throw new \InvalidArgumentException('Retained encoding Differences require byte codes and glyph names.');
                }
            }
        } elseif (in_array($name, ['Filter', 'F', 'DescendantFonts', 'DecodeParms', 'DP'], true)) {
            foreach ($value->items as $item) {
                $item = $item instanceof IndirectReference ? $item->target() : $item;
                $valid = match ($name) {
                    'Filter', 'F' => $item instanceof NameType,
                    'DescendantFonts' => $item instanceof DictionaryType,
                    default => $item instanceof DictionaryType || $item instanceof NullType,
                };
                if (!$valid) {
                    throw new \InvalidArgumentException('A retained rendering-resource array contains an unsafe or malformed value.');
                }
            }
        }
    }

    /** @param list<string> $keys */
    private function dictionaryKeys(DictionaryType $dictionary, array $keys): void
    {
        foreach ($dictionary->entries() as $entry) {
            if (!in_array($entry->key->value, $keys, true) && !in_array($entry->key->value, ['Metadata', 'PieceInfo', 'LastModified', 'StructParent', 'StructParents', 'AF', 'OPI', 'Alternates', 'Name', 'Intent'], true)) {
                throw new \InvalidArgumentException('A retained rendering-resource dictionary contains an entry outside its normalized context.');
            }
        }
    }

    private function numericValues(ArrayType $array, bool $nested): void
    {
        foreach ($array->items as $item) {
            $item = $item instanceof IndirectReference ? $item->target() : $item;
            if ($nested && $item instanceof ArrayType) {
                $this->numericValues($item, false);
            } elseif (!$item instanceof NumericType || !is_finite((float) $item->value)) {
                throw new \InvalidArgumentException('A retained rendering-resource numeric array contains graph-bearing or nonnumeric data.');
            }
        }
    }

    private function pixels(StreamObject $image): void
    {
        $dictionary = $image->dictionary();
        $this->dictionaryKeys($dictionary, ['Type', 'Subtype', 'Width', 'Height', 'ColorSpace', 'BitsPerComponent', 'ImageMask', 'Decode', 'Interpolate', 'Mask', 'SMask', 'SMaskInData', 'Matte', 'Filter', 'DecodeParms']);
        $width = $dictionary->get('Width');
        $height = $dictionary->get('Height');
        $bits = $dictionary->get('BitsPerComponent');
        $mask = $dictionary->get('ImageMask');
        $space = $dictionary->get('ColorSpace');
        $components = $mask instanceof BooleanType && $mask->value ? 1 : ($space instanceof NameType ? match ($space->value) {
            'DeviceGray' => 1, 'DeviceRGB' => 3, 'DeviceCMYK' => 4, default => 0
        } : 0);
        $bits = $mask instanceof BooleanType && $mask->value && $bits === null ? new NumericType(1) : $bits;
        if (!is_string($image->data) || !$width instanceof NumericType || !is_int($width->value) || $width->value < 1 || !$height instanceof NumericType || !is_int($height->value) || $height->value < 1 || !$bits instanceof NumericType || !is_int($bits->value) || !in_array($bits->value, [1, 2, 4, 8, 16], true) || $components === 0) {
            throw new \InvalidArgumentException('Retained images require normalized device-color pixel rows or an explicit image sanitizer.');
        }
        if ($mask instanceof BooleanType && $mask->value && $bits->value !== 1) {
            throw new \InvalidArgumentException('A normalized image mask has one bit per sample.');
        }
        foreach ($image->filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Opaque JPEG/JPX/JBIG2 image payloads must be decoded and sanitized before redaction; hidden codec metadata cannot be retained.');
            }
        }
        $bitsPerPixel = $components * $bits->value;
        if ($width->value > intdiv(PHP_INT_MAX - 7, $bitsPerPixel)) {
            throw new \OverflowException('Retained pixel row exceeds its explicit byte budget.');
        }
        $row = intdiv($width->value * $bitsPerPixel + 7, 8);
        if ($height->value > intdiv($this->limits->bytes, $row) || strlen($image->data) !== $row * $height->value) {
            throw new \InvalidArgumentException('Retained image data must contain exactly the normalized pixel rows, without trailing metadata.');
        }
    }
}
