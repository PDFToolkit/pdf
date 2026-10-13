<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Definition\ChoiceFieldTable231Definition;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Explicit option ordering; no locale-dependent sorting or automatic value selection. */
final class ChoiceField extends TerminalField
{
    public function __construct(?PartialFieldName $name, public readonly ChoiceOptions $options, ?ChoiceFieldFlags $flags = null, public readonly ?ChoiceFieldValue $value = null, ?ChoiceSelection $selection = null, FieldTypeDeclaration $declaration = FieldTypeDeclaration::Explicit, public readonly ?ChoiceFieldValue $defaultValue = null, public readonly ?ChoiceValueDomain $inheritedValueDomain = null, Entry ...$entries)
    {
        $managed = [new Entry(new NameType('Opt'), $options->object())];
        if ($value !== null) {
            $managed[] = new Entry(new NameType('V'), $value->object);
        }
        if ($defaultValue !== null) {
            $managed[] = new Entry(new NameType('DV'), $defaultValue->object);
        }
        if ($selection !== null) {
            $managed[] = new Entry(new NameType('I'), $selection->object());
        }
        parent::__construct(new ChoiceFieldTable231Definition(), $name, FieldType::Choice, $declaration, $flags, ...[...$managed, ...$entries]);
    }

    public function validate(): void
    {
        parent::validate();
        $flags = $this->flags();
        $mask = 7;
        foreach (ChoiceFieldFlag::cases() as $flag) {
            $mask |= $flag->value;
        }
        if (($flags & ~$mask) !== 0 || (($flags & ChoiceFieldFlag::Edit->value) !== 0 && ($flags & ChoiceFieldFlag::Combo->value) === 0) || (($flags & ChoiceFieldFlag::DoNotSpellCheck->value) !== 0 && ($flags & (ChoiceFieldFlag::Combo->value | ChoiceFieldFlag::Edit->value)) !== (ChoiceFieldFlag::Combo->value | ChoiceFieldFlag::Edit->value))) {
            throw new \InvalidArgumentException('Choice field flags are inconsistent with Combo/Edit or their field type.');
        }
        $multiple = ($flags & ChoiceFieldFlag::MultiSelect->value) !== 0;
        $editable = ($flags & (ChoiceFieldFlag::Combo->value | ChoiceFieldFlag::Edit->value)) === (ChoiceFieldFlag::Combo->value | ChoiceFieldFlag::Edit->value);
        $values = [];
        foreach (['V', 'DV'] as $key) {
            $value = $this->effective($key);
            $declaration = $key === 'V' ? $this->value : $this->defaultValue;
            $domain = $declaration === null ? $this->inheritedValueDomain : $declaration->domain;
            if ($value !== null && $domain === null) {
                throw new \InvalidArgumentException('Choice values require an explicit display/export domain declaration.');
            }
            $items = $value instanceof ArrayType ? $value->items : ($value === null || $value instanceof NullType ? [] : [$value]);
            if ($value instanceof ArrayType && !$multiple) {
                throw new \InvalidArgumentException('An array choice value requires MultiSelect.');
            }
            foreach ($items as $item) {
                if (!$item instanceof TextString) {
                    throw new \InvalidArgumentException('Choice values are explicit text strings.');
                }
                $matches = array_filter($this->options->items, static fn (ChoiceOption $option) => ($domain === ChoiceValueDomain::ExportValues ? $option->value() : $option->display)->value === $item->value);
                if (!$editable && $matches === []) {
                    throw new \InvalidArgumentException('A noneditable choice value must occur in Opt.');
                }
            }
            if ($key === 'V') {
                $values = $items;
            }
        }
        $indices = $this->get('I');
        if ($indices !== null && (!$indices instanceof ArrayType || !$multiple)) {
            throw new \InvalidArgumentException('Selection indices require a MultiSelect field.');
        }
        $previous = -1;
        foreach ($indices instanceof ArrayType ? $indices->items : [] as $index) {
            if (!$index instanceof IntegerType || $index->integer() <= $previous || !isset($this->options->items[$index->integer()])) {
                throw new \InvalidArgumentException('Choice selection indices must be ascending, unique and within Opt.');
            }
            $previous = $index->value;
        }
        $ambiguous = false;
        $exportNames = [];
        foreach ($this->options->items as $option) {
            $export = $option->value()->value;
            if (isset($exportNames[$export]) && $exportNames[$export] !== $option->display->value) {
                $ambiguous = true;
            }
            $exportNames[$export] = $option->display->value;
        }
        if ($multiple && ($this->effective('V') instanceof ArrayType || $ambiguous) && !$indices instanceof ArrayType) {
            throw new \InvalidArgumentException('MultiSelect array values or duplicate export values require explicit I indices.');
        }
        $top = $this->get('TI');
        if ($top !== null && (!$top instanceof IntegerType || $top->value < 0 || ($this->options->items !== [] && $top->value >= count($this->options->items)))) {
            throw new \InvalidArgumentException('The first visible choice option index must be within Opt.');
        }
        // ISO table 231 explicitly makes V authoritative when it differs from I.
    }
}
