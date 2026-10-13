<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContentWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\MarkedContentPointWithProperties;
use PDF\Syntax\ContentStreamsResources\Operators\PaintShading;
use PDF\Syntax\ContentStreamsResources\Operators\SetGraphicsStateParameters;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingColorSpace;
use PDF\Syntax\ContentStreamsResources\Operators\SetStrokingColorSpace;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** Resolves independent form resources and colour arity whenever the form sets its space. */
final readonly class XObjectResourcesValidator
{
    public function validate(ContentStreams $content, DictionaryType $resources): void
    {
        /** @var array{?array{ColourSpaceFamily, int}, ?array{ColourSpaceFamily, int}} $spaces */
        $spaces = [null, null];
        $stack = [];
        foreach ($content->operators as $operator) {
            $symbol = $operator->symbol();
            if ($symbol === 'q') {
                $stack[] = $spaces;
            } elseif ($symbol === 'Q') {
                $saved = array_pop($stack);
                if ($saved === null) {
                    throw new \InvalidArgumentException('Form resource-state restore lacks a matching save.');
                }
                $spaces = $saved;
            }
            if ($operator instanceof SetTextFontAndSize) {
                $font = $this->resource($resources, 'Font', $operator->font);
                if (!$font instanceof DictionaryType) {
                    throw new \InvalidArgumentException('A form font resource must resolve to a dictionary.');
                }
            }
            if ($operator instanceof InvokeXObject && !$this->resource($resources, 'XObject', $operator->resourceName) instanceof StreamObject) {
                throw new \InvalidArgumentException('A form XObject resource must resolve to a stream.');
            }
            if ($operator instanceof SetGraphicsStateParameters && !$this->resource($resources, 'ExtGState', $operator->resourceName) instanceof DictionaryType) {
                throw new \InvalidArgumentException('A form gs resource must resolve to an ExtGState dictionary.');
            }
            if ($operator instanceof PaintShading) {
                $shading = $this->resource($resources, 'Shading', $operator->resourceName);
                if ($shading instanceof StreamObject) {
                    $shading = $shading->dictionary();
                }
                if (!$shading instanceof DictionaryType || !$shading->get('ShadingType') instanceof NumericType) {
                    throw new \InvalidArgumentException('A form shading resource must declare ShadingType.');
                }
            }
            if (($operator instanceof BeginMarkedContentWithProperties || $operator instanceof MarkedContentPointWithProperties) && $operator->properties instanceof NameType && !$this->resource($resources, 'Properties', $operator->properties) instanceof DictionaryType) {
                throw new \InvalidArgumentException('A named form property list must resolve to a dictionary.');
            }
            if ($operator instanceof SetStrokingColorSpace || $operator instanceof SetNonstrokingColorSpace) {
                $name = $operator->colorSpace;
                $space = $this->device($name);
                if ($space === null) {
                    $space = $this->space($this->resource($resources, 'ColorSpace', $name));
                }
                $spaces[$operator instanceof SetStrokingColorSpace ? 0 : 1] = $space;
            }
            foreach (['G' => [0, ColourSpaceFamily::DeviceGray, 1], 'g' => [1, ColourSpaceFamily::DeviceGray, 1], 'RG' => [0, ColourSpaceFamily::DeviceRGB, 3], 'rg' => [1, ColourSpaceFamily::DeviceRGB, 3], 'K' => [0, ColourSpaceFamily::DeviceCMYK, 4], 'k' => [1, ColourSpaceFamily::DeviceCMYK, 4]] as $selection => [$slot, $family, $count]) {
                if ($selection === $symbol) {
                    $spaces[$slot] = [$family, $count];
                }
            }
            if (in_array($symbol, ['SC', 'sc', 'SCN', 'scn'], true)) {
                $space = $spaces[in_array($symbol, ['SC', 'SCN'], true) ? 0 : 1];
                if ($space === null) {
                    continue; // Inherited caller colour state remains an explicit external input.
                }
                [$family, $count] = $space;
                $operands = $operator->operands;
                if (in_array($symbol, ['SC', 'sc'], true) && in_array($family, [ColourSpaceFamily::Pattern, ColourSpaceFamily::Separation, ColourSpaceFamily::DeviceN, ColourSpaceFamily::ICCBased], true)) {
                    throw new \InvalidArgumentException('This colour space requires SCN/scn (table74).');
                }
                if ($family === ColourSpaceFamily::Pattern) {
                    $name = array_pop($operands);
                    if (!$name instanceof NameType) {
                        throw new \InvalidArgumentException('Pattern selections end with a pattern resource name.');
                    }
                    $pattern = $this->resource($resources, 'Pattern', $name);
                    $dictionary = $pattern instanceof StreamObject ? $pattern->dictionary() : $pattern;
                    $paint = $dictionary instanceof DictionaryType ? $dictionary->get('PaintType') : null;
                    $type = $dictionary instanceof DictionaryType ? $dictionary->get('PatternType') : null;
                    if (!$type instanceof NumericType || !in_array($type->value, [1, 2], true) || (($paint instanceof NumericType && $paint->value == 2) !== ($count > 0))) {
                        throw new \InvalidArgumentException('Pattern space and coloured/stencil resource must agree.');
                    }
                }
                if (count($operands) !== $count) {
                    throw new \InvalidArgumentException('Form colour operands must match the explicitly selected colour space.');
                }
                foreach ($operands as $operand) {
                    if (!$operand instanceof NumericType) {
                        throw new \InvalidArgumentException('Colour components must be direct numbers.');
                    }
                }
            }
        }
    }

    private function resource(DictionaryType $resources, string $category, NameType $name): BasicType
    {
        $dictionary = $this->target($resources->get($category));
        $value = $dictionary instanceof DictionaryType ? $this->target($dictionary->get($name)) : null;
        if ($value === null) {
            throw new \InvalidArgumentException('Independent form resource is missing: /' . $category . '/' . $name->value . '.');
        }
        return $value;
    }

    private function target(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }

    /** @return ?array{ColourSpaceFamily, int} */
    private function device(NameType $name): ?array
    {
        return match ($name->value) {
            'DeviceGray' => [ColourSpaceFamily::DeviceGray, 1],
            'DeviceRGB' => [ColourSpaceFamily::DeviceRGB, 3],
            'DeviceCMYK' => [ColourSpaceFamily::DeviceCMYK, 4],
            'Pattern' => [ColourSpaceFamily::Pattern, 0],
            default => null,
        };
    }

    /** @return array{ColourSpaceFamily, int} */
    private function space(BasicType $value): array
    {
        if ($value instanceof ColourSpace) {
            return [$value->family(), $value->componentCount()];
        }
        if ($value instanceof NameType) {
            $device = $this->device($value);
            if ($device !== null) {
                return $device;
            }
        }
        // Native constructions expose their dimensions. Generic schema arrays
        // remain explicit low-level objects and cannot promise verified arity.
        throw new \InvalidArgumentException('Verified form colour resources need a native ColourSpace or a device name.');
    }
}
