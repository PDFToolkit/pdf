<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Definition\CheckBoxAndRadioButtonFieldsTable227Definition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\DataStructure\TextString;

/** Check boxes, radio groups and pushbuttons, ISO 12.7.4.2, PDF pp.447–450. */
final class ButtonField extends TerminalField
{
    public function __construct(?PartialFieldName $name = null, ?ButtonFieldFlags $flags = null, ?NameType $value = null, FieldTypeDeclaration $declaration = FieldTypeDeclaration::Explicit, Entry ...$entries)
    {
        $managed = $value === null ? [] : [new Entry(new NameType('V'), $value)];
        parent::__construct(new CheckBoxAndRadioButtonFieldsTable227Definition(), $name, FieldType::Button, $declaration, $flags, ...[...$managed, ...$entries]);
    }

    public function validate(): void
    {
        parent::validate();
        $flags = $this->flags();
        $mask = 7;
        foreach (ButtonFieldFlag::cases() as $flag) {
            $mask |= $flag->value;
        }
        $radio = ($flags & ButtonFieldFlag::Radio->value) !== 0;
        $push = ($flags & ButtonFieldFlag::Pushbutton->value) !== 0;
        $unison = ($flags & ButtonFieldFlag::RadiosInUnison->value) !== 0;
        if (($flags & ~$mask) !== 0 || ($push && $radio) || (!$radio && ($flags & (ButtonFieldFlag::NoToggleToOff->value | ButtonFieldFlag::RadiosInUnison->value)) !== 0)) {
            throw new \InvalidArgumentException('Button flags are inconsistent with Pushbutton/Radio or their field type.');
        }
        $value = $this->effective('V');
        $default = $this->effective('DV');
        if ($push && ($value !== null || $default !== null)) {
            throw new \InvalidArgumentException('A pushbutton does not retain a field value or default value.');
        }
        foreach ([$value, $default] as $item) {
            if ($item !== null && !$item instanceof NameType) {
                throw new \InvalidArgumentException('A check box or radio value must be a name.');
            }
        }
        $selected = $value instanceof NameType ? $value->value : 'Off';
        $options = $this->effective('Opt');
        $onCount = 0;
        foreach ($this->widgets() as $index => $widget) {
            $normal = $widget->appearance?->normal;
            if ($push) {
                if ($normal !== null && !$normal instanceof FormAppearanceStream) {
                    throw new \InvalidArgumentException('A pushbutton normal appearance is a stream.');
                }
                continue;
            }
            if ($normal === null && $this->tree()?->needAppearances->value === true) {
                continue;
            }
            if (!$normal instanceof AppearanceStates || !$normal->has('Off') || count($normal) !== 2) {
                throw new \InvalidArgumentException('Each check box/radio widget has exactly Off and one named on appearance.');
            }
            if ($options !== null && !$normal->has((string) $index)) {
                throw new \InvalidArgumentException('With button Opt, each AP on-state name equals its zero-based widget index.');
            }
            $state = $widget->state;
            if ($state === null) {
                throw new \InvalidArgumentException('Button widgets require an explicit AS state.');
            }
            if ($state->value !== 'Off') {
                if ($state->value !== $selected) {
                    throw new \InvalidArgumentException('An on widget state must match its field V.');
                }
                ++$onCount;
            }
            if (!$radio && $normal->has($selected) && $state->value !== $selected) {
                throw new \InvalidArgumentException('Check box widgets representing the same field value must agree.');
            }
            if ($radio && $unison && $normal->has($selected) && $state->value !== $selected) {
                throw new \InvalidArgumentException('Radio widgets sharing a value must agree when RadiosInUnison is set.');
            }
        }
        if ($radio && !$unison && $onCount > 1) {
            throw new \InvalidArgumentException('A mutually exclusive radio group can select at most one widget.');
        }
        if (!$push && $this->widgets() !== [] && $selected !== 'Off' && $onCount === 0 && $this->tree()?->needAppearances->value !== true) {
            throw new \InvalidArgumentException('A non-Off button V requires a corresponding selected widget.');
        }
        if ($radio && ($flags & ButtonFieldFlag::NoToggleToOff->value) !== 0 && $selected === 'Off') {
            throw new \InvalidArgumentException('NoToggleToOff requires a selected radio value.');
        }
        if ($options !== null) {
            if ($push || !$options instanceof ArrayType || count($options) !== count($this->widgets())) {
                throw new \InvalidArgumentException('Button export options correspond one-to-one to its widgets.');
            }
            foreach ($options->items as $option) {
                if (!$option instanceof TextString) {
                    throw new \InvalidArgumentException('Button export values are text strings.');
                }
            }
        }
    }
}
