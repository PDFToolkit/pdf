<?php

declare(strict_types=1);

namespace PDF\Graphics\Patterns;

use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\InlineImage;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\SetGraphicsStateParameters;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidthAndBoundingBox;
use PDF\Syntax\ContentStreamsResources\Operators\SetGlyphWidth;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\NumericType;

/** Verifies stencil cells and invoked forms/masks without parsing external content formats (8.7.3.3). */
final readonly class UncolouredPatternValidator
{
    public function validate(ContentStreams $content, DictionaryType $resources): void
    {
        $this->visit($content, $resources, []);
    }

    /** @param array<int, true> $active */
    private function visit(ContentStreams $content, DictionaryType $resources, array $active): void
    {
        $id = spl_object_id($content);
        if (isset($active[$id]) || count($active) > 256) {
            throw new \InvalidArgumentException('Stencil pattern resource recursion exceeds its construction limit or forms a cycle.');
        }
        $active[$id] = true;
        foreach ($content->operators as $operator) {
            if (in_array($operator->symbol(), ['CS', 'cs', 'SC', 'sc', 'SCN', 'scn', 'G', 'g', 'RG', 'rg', 'K', 'k', 'ri', 'sh'], true)) {
                throw new \InvalidArgumentException('An uncoloured pattern cannot specify colours or colour-related graphics-state parameters.');
            }
            if ($operator instanceof InlineImage) {
                $mask = $operator->dictionary->get('ImageMask') ?? $operator->dictionary->get('IM');
                if (!$mask instanceof BooleanType || !$mask->value) {
                    throw new \InvalidArgumentException('An uncoloured pattern may paint an inline image only as an image mask.');
                }
            }
            if ($operator instanceof SetGraphicsStateParameters) {
                $states = $this->target($resources->get('ExtGState'));
                $state = $states instanceof DictionaryType ? $this->target($states->get($operator->resourceName)) : null;
                if (!$state instanceof DictionaryType) {
                    throw new \InvalidArgumentException('An uncoloured pattern must resolve its gs resource explicitly.');
                }
                foreach (['BG', 'BG2', 'UCR', 'UCR2', 'TR', 'TR2', 'HT'] as $key) {
                    if ($state->has($key)) {
                        throw new \InvalidArgumentException('An uncoloured pattern cannot select colour-related ExtGState parameters.');
                    }
                }
            }
            if ($operator instanceof SetTextFontAndSize) {
                $fonts = $this->target($resources->get('Font'));
                $font = $fonts instanceof DictionaryType ? $this->target($fonts->get($operator->font)) : null;
                if (!$font instanceof DictionaryType) {
                    throw new \InvalidArgumentException('An uncoloured pattern must resolve its font resource explicitly.');
                }
                $subtype = $font->get('Subtype');
                if ($subtype instanceof NameType && $subtype->value === 'Type3') {
                    $procedures = $this->target($font->get('CharProcs'));
                    $own = $this->target($font->get('Resources'));
                    if (!$procedures instanceof DictionaryType) {
                        throw new \InvalidArgumentException('A Type 3 stencil font requires explicit CharProcs.');
                    }
                    // All supplied glyphs must be stencils. This avoids deriving
                    // character selection from an external font encoding parser.
                    foreach ($procedures->entries() as $entry) {
                        $glyph = $this->target($entry->value);
                        if (!$glyph instanceof StreamObject || !$glyph->data instanceof ContentStreams) {
                            throw new \InvalidArgumentException('Type 3 glyphs in a verified stencil pattern require typed content.');
                        }
                        $metrics = $glyph->data->operators[0] ?? null;
                        if (!$metrics instanceof SetGlyphWidth && !$metrics instanceof SetGlyphWidthAndBoundingBox) {
                            throw new \InvalidArgumentException('A Type 3 glyph requires typed d0/d1 metrics.');
                        }
                        $glyph->data->validate();
                        $this->visit($glyph->data, $own instanceof DictionaryType ? $own : $resources, $active);
                    }
                }
            }
            if ($operator instanceof InvokeXObject) {
                $objects = $this->target($resources->get('XObject'));
                $object = $objects instanceof DictionaryType ? $this->target($objects->get($operator->resourceName)) : null;
                if (!$object instanceof StreamObject) {
                    throw new \InvalidArgumentException('A stencil pattern must resolve each XObject resource.');
                }
                $subtype = $object->dictionary()->get('Subtype');
                if ($subtype instanceof NameType && $subtype->value === 'Image') {
                    $this->image($object, $active);
                } elseif ($subtype instanceof NameType && $subtype->value === 'Form' && $object->data instanceof ContentStreams) {
                    $own = $this->target($object->dictionary()->get('Resources'));
                    $this->visit($object->data, $own instanceof DictionaryType ? $own : $resources, $active);
                } else {
                    throw new \InvalidArgumentException('Verified uncoloured pattern forms require typed content streams.');
                }
            }
        }
    }

    private function target(?BasicType $object): ?BasicType
    {
        return $object instanceof IndirectReference ? $object->target() : $object;
    }

    /** @param array<int, true> $active */
    private function image(StreamObject $image, array $active): void
    {
        $id = spl_object_id($image);
        if (isset($active[$id]) || count($active) > 256) {
            throw new \InvalidArgumentException('Stencil alternate-image recursion exceeds its construction limit or forms a cycle.');
        }
        $active[$id] = true;
        $dictionary = $image->dictionary();
        $mask = $dictionary->get('ImageMask');
        $bits = $dictionary->get('BitsPerComponent');
        $subtype = $dictionary->get('Subtype');
        if (!$subtype instanceof NameType || $subtype->value !== 'Image' || !$mask instanceof BooleanType || !$mask->value || $dictionary->has('ColorSpace') || $dictionary->has('Mask') || ($bits !== null && (!$bits instanceof NumericType || $bits->value != 1))) {
            throw new \InvalidArgumentException('An uncoloured pattern may paint only valid stencil image XObjects.');
        }
        $alternates = $this->target($dictionary->get('Alternates'));
        if ($alternates !== null && !$alternates instanceof ArrayType) {
            throw new \InvalidArgumentException('Stencil image alternatives require an explicit array.');
        }
        if ($alternates instanceof ArrayType) {
            foreach ($alternates->items as $alternate) {
                $alternate = $this->target($alternate);
                $target = $alternate instanceof DictionaryType ? $this->target($alternate->get('Image')) : null;
                if (!$target instanceof StreamObject) {
                    throw new \InvalidArgumentException('Stencil image alternatives must resolve to image streams.');
                }
                $this->image($target, $active);
            }
        }
    }
}
