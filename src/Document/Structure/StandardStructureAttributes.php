<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\Definition\AdditionalStandardLayoutAttributesSpecificToBlockLevelStructureElementsContinuedTable344Definition;
use PDF\Specification\Definition\PrintFieldAttributesTable348Definition;
use PDF\Specification\Definition\StandardColumnAttributesTable346Definition;
use PDF\Specification\Definition\StandardLayoutAttributesCommonToAllStandardStructureTypesTable343Definition;
use PDF\Specification\Definition\StandardLayoutAttributesSpecificToInlineLevelStructureElementsTable345Definition;
use PDF\Specification\Definition\StandardListAttributeTable347Definition;
use PDF\Specification\Definition\StandardTableAttributesTable349Definition;
use PDF\Specification\SourceReference;
use PDF\Specification\TableDefinition;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** Standard owner domains composed from tables 343–349, PDF pages 606–624. */
final readonly class StandardStructureAttributes
{
    public function definition(NameType $owner): ?TableDefinition
    {
        return match ($owner->value) {
            'Layout' => new TableDefinition(new SourceReference('343–346', '14.8.5.4', 605), 'Standard Layout attributes', [], [
                new StandardLayoutAttributesCommonToAllStandardStructureTypesTable343Definition(),
                new AdditionalStandardLayoutAttributesSpecificToBlockLevelStructureElementsContinuedTable344Definition(),
                new StandardLayoutAttributesSpecificToInlineLevelStructureElementsTable345Definition(),
                new StandardColumnAttributesTable346Definition(),
            ]),
            'List' => new StandardListAttributeTable347Definition(),
            'PrintField' => new PrintFieldAttributesTable348Definition(),
            'Table' => new StandardTableAttributesTable349Definition(),
            default => null,
        };
    }

    public function inheritable(NameType $owner, NameType $attribute): bool
    {
        $definition = $this->definition($owner);
        if ($definition === null || !$definition->has($attribute)) {
            return false;
        }
        $wording = $definition->entry($attribute)->requirement->wording ?? '';
        return str_contains($wording, 'inheritable') && !str_contains($wording, 'not inheritable');
    }

    /** Validates explicit standard domains without interpreting application-owned extensions. */
    public function validate(DictionaryType $dictionary, NameType $owner): void
    {
        $definition = $this->definition($owner);
        if ($definition === null) {
            return;
        }
        foreach ($dictionary->entries() as $entry) {
            $key = $entry->key->value;
            $value = $entry->value;
            if ($definition->has($key)) {
                $definition->entry($key)->type->validate($value);
            }
            $domain = match ($owner->value . '/' . $key) {
                'Layout/Placement' => ['Block', 'Inline', 'Before', 'Start', 'End'],
                'Layout/WritingMode' => ['LrTb', 'RlTb', 'TbRl'],
                'Layout/TextAlign', 'Layout/RubyAlign' => ['Start', 'Center', 'End', 'Justify', ...($key === 'RubyAlign' ? ['Distribute'] : [])],
                'Layout/BlockAlign' => ['Before', 'Middle', 'After', 'Justify'],
                'Layout/InlineAlign' => ['Start', 'Center', 'End'],
                'Layout/RubyPosition' => ['Before', 'After', 'Warichu', 'Inline'],
                'Layout/TextDecorationType' => ['None', 'Underline', 'Overline', 'LineThrough'],
                'Layout/GlyphOrientationVertical', 'Layout/Width', 'Layout/Height' => ['Auto'],
                'Layout/LineHeight' => ['Normal', 'Auto'],
                'PrintField/Role' => ['rb', 'cb', 'pb', 'tv'],
                'PrintField/checked' => ['on', 'off', 'neutral'],
                'Table/Scope' => ['Row', 'Column', 'Both'],
                default => null,
            };
            if ($domain !== null && $value instanceof NameType && !in_array($value->value, $domain, true)) {
                throw new \InvalidArgumentException('Invalid standard attribute value for /' . $owner->value . '/' . $key . '.');
            }
            if ($owner->value === 'Layout') {
                if ($key === 'GlyphOrientationVertical' && $value instanceof NumericType) {
                    if ($value->value < -180 || $value->value > 360 || fmod((float) $value->value, 90.0) !== 0.0) {
                        throw new \InvalidArgumentException('Vertical glyph orientation must be a multiple of 90 between -180 and +360.');
                    }
                } elseif (in_array($key, ['BackgroundColor', 'Color', 'TextDecorationColor'], true)) {
                    $this->rgb($value);
                } elseif ($key === 'BorderColor') {
                    if (!$value instanceof ArrayType) {
                        throw new \InvalidArgumentException('BorderColor requires RGB or four edge colours.');
                    }
                    if (count($value->items) === 3) {
                        $this->rgb($value);
                    } elseif (count($value->items) === 4) {
                        foreach ($value->items as $edge) {
                            if (!$edge instanceof NullType) {
                                $this->rgb($edge);
                            }
                        }
                    } else {
                        throw new \InvalidArgumentException('BorderColor requires RGB or four edge colours.');
                    }
                } elseif (in_array($key, ['BorderStyle', 'TBorderStyle'], true)) {
                    $styles = $value instanceof ArrayType ? $value->items : [$value];
                    if ($value instanceof ArrayType && count($styles) !== 4) {
                        throw new \InvalidArgumentException('A border-style array requires four edge styles.');
                    }
                    foreach ($styles as $style) {
                        if (!$style instanceof NullType && (!$style instanceof NameType || !in_array($style->value, ['None', 'Hidden', 'Dotted', 'Dashed', 'Solid', 'Double', 'Groove', 'Ridge', 'Inset', 'Outset'], true))) {
                            throw new \InvalidArgumentException('Invalid standard border style.');
                        }
                    }
                } elseif (in_array($key, ['Padding', 'TPadding', 'BorderThickness'], true)) {
                    $numbers = $value instanceof ArrayType ? $value->items : [$value];
                    if ($value instanceof ArrayType && count($numbers) !== 4) {
                        throw new \InvalidArgumentException('Edge dimensions require a scalar or four edge values.');
                    }
                    foreach ($numbers as $number) {
                        if ($key === 'BorderThickness' && $number instanceof NullType) {
                            continue;
                        }
                        if (!$number instanceof NumericType || ($key === 'BorderThickness' && $number->value < 0)) {
                            throw new \InvalidArgumentException('Invalid numeric edge dimension.');
                        }
                    }
                } elseif ($key === 'TextDecorationThickness' && $value instanceof NumericType && $value->value < 0) {
                    throw new \InvalidArgumentException('Text-decoration thickness must be non-negative.');
                } elseif ($key === 'ColumnCount' && $value instanceof IntegerType && $value->integer() < 1) {
                    throw new \InvalidArgumentException('A column count must be positive.');
                }
            } elseif ($owner->value === 'Table') {
                if (in_array($key, ['RowSpan', 'ColSpan'], true) && $value instanceof IntegerType && $value->integer() < 1) {
                    throw new \InvalidArgumentException('A table span must be positive.');
                }
                if ($key === 'Headers' && $value instanceof ArrayType) {
                    foreach ($value->items as $id) {
                        if (!$id instanceof StringType) {
                            throw new \InvalidArgumentException('Table headers must be byte-string element identifiers.');
                        }
                    }
                }
            }
        }
    }

    private function rgb(BasicType $value): void
    {
        if (!$value instanceof ArrayType || count($value->items) !== 3) {
            throw new \InvalidArgumentException('An RGB attribute requires exactly three colour components.');
        }
        foreach ($value->items as $component) {
            if (!$component instanceof NumericType || $component->value < 0 || $component->value > 1) {
                throw new \InvalidArgumentException('An RGB attribute component must be a number between zero and one.');
            }
        }
    }

    /** Context-dependent defaults (colour, dimensions, line height) need explicit caller context. */
    public function defaultValue(NameType $owner, NameType $attribute): ?BasicType
    {
        if ($owner->value === 'Layout') {
            return match ($attribute->value) {
                'Placement' => new NameType('Inline'),
                'WritingMode' => new NameType('LrTb'),
                'BorderStyle', 'TBorderStyle', 'TextDecorationType' => new NameType('None'),
                'Padding', 'TPadding', 'SpaceBefore', 'SpaceAfter', 'StartIndent', 'EndIndent', 'TextIndent', 'BaselineShift' => new IntegerType(0),
                'TextAlign', 'InlineAlign' => new NameType('Start'),
                'BlockAlign' => new NameType('Before'),
                'Width', 'Height', 'GlyphOrientationVertical' => new NameType('Auto'),
                'LineHeight' => new NameType('Normal'),
                'RubyAlign' => new NameType('Distribute'),
                'RubyPosition' => new NameType('Before'),
                'ColumnCount' => new IntegerType(1),
                default => null,
            };
        }
        if ($owner->value === 'Table') {
            return match ($attribute->value) {
                'RowSpan', 'ColSpan' => new IntegerType(1),
                default => null,
            };
        }
        if ($owner->value === 'List' && $attribute->value === 'ListNumbering') {
            return new NameType('None');
        }
        if ($owner->value === 'PrintField' && $attribute->value === 'checked') {
            return new NameType('off');
        }
        return null;
    }
}
