<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Forms\ButtonFieldFlag;
use PDF\Document\Forms\ChoiceFieldFlag;
use PDF\Document\Forms\TextFieldFlag;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** Conservative filling of existing terminal fields, with their immutable tree and policies. */
final class ProtectedFormFillChanges
{
    public function classify(ProtectedRevisionChanges $changes, SignaturePermissionHistory $history): int
    {
        $before = new FrozenFormFields($changes->prior);
        $after = new FrozenFormFields($changes->current);
        $priorFields = $before->fields();
        $fields = $after->fields();
        if (array_keys($priorFields) !== array_keys($fields)) {
            throw new \LogicException('Protected filling cannot add, remove or reorder field identities.');
        }
        $count = 0;
        foreach ($fields as $id => $field) {
            $old = $priorFields[$id];
            if ($old->qualifiedName !== $field->qualifiedName || count($old->widgets) !== count($field->widgets)) {
                throw new \LogicException('Protected filling preserves names and widget ownership.');
            }
            $changed = !$changes->sameDictionary($old->dictionary, $field->dictionary);
            foreach ($field->widgets as $index => $widget) {
                $previous = $old->widgets[$index];
                if ((string) $previous->identifier !== (string) $widget->identifier) {
                    throw new \LogicException('Protected filling preserves widget identities and order.');
                }
                $changed = $changed || !$changes->same($previous->snapshot, $widget->snapshot);
            }
            if (!$changed) {
                continue;
            }
            $history->assertFormFilling($field->identifier, $field->qualifiedName);
            $type = $changes->current->resolve($field->effective('FT'));
            $flags = $changes->current->resolve($field->effective('Ff'));
            if (!$type instanceof NameType || !in_array($type->value, ['Tx', 'Ch', 'Btn'], true)
                || ($flags !== null && (!$flags instanceof IntegerType || $flags->integer() < 0))) {
                throw new \LogicException('Protected filling recognizes only terminal text, choice and button fields.');
            }
            $mask = $flags instanceof IntegerType ? $flags->integer() : 0;
            $known = 7;
            $defined = match ($type->value) {
                'Tx' => TextFieldFlag::cases(),
                'Ch' => ChoiceFieldFlag::cases(),
                'Btn' => ButtonFieldFlag::cases(),
            };
            foreach ($defined as $flag) {
                $known |= $flag->value;
            }
            if (($mask & ~$known) !== 0) {
                throw new \LogicException('Protected filling refuses unclassified field flags.');
            }
            if (($mask & 1) !== 0) {
                throw new \LogicException('A read-only field cannot be filled in a protected revision.');
            }
            $merged = false;
            foreach ($field->widgets as $widget) {
                $merged = $merged || (string) $widget->identifier === $id;
            }
            $keys = ['V'];
            if ($type->value === 'Ch') {
                array_push($keys, 'I', 'TI');
            }
            if ($merged) {
                array_push($keys, ...($type->value === 'Btn' ? ['AS'] : ['AP', 'AS']));
            }
            if (!$changes->sameDictionary($old->dictionary, $field->dictionary, $keys)) {
                throw new \LogicException('Protected filling preserves field defaults, options, flags, actions and structure.');
            }
            $value = $field->effective('V');
            if ($field->dictionary->get('V') instanceof IndirectReference || $value instanceof IndirectReference) {
                throw new \LogicException('Protected filling requires direct plain text, choice or button values.');
            }
            if ($value instanceof NullType) {
                $value = null;
            }
            match ($type->value) {
                'Tx' => $this->text($changes->current, $field, $value, $mask),
                'Ch' => $this->choice($changes->current, $field, $value, $mask),
                'Btn' => $this->button($changes->current, $field, $value, $mask),
            };
            $changes->assertExclusiveReferences($field->identifier, $field->owners);
            $changes->change($field->identifier);
            foreach ($field->widgets as $index => $widget) {
                $previous = $old->widgets[$index];
                $dictionary = $widget->dictionary ?? throw new \LogicException('A protected widget is a dictionary.');
                $oldDictionary = $previous->dictionary ?? throw new \LogicException('A previous protected widget is a dictionary.');
                if ((string) $widget->identifier !== $id) {
                    if (!$changes->sameDictionary($oldDictionary, $dictionary, $type->value === 'Btn' ? ['AS'] : ['AP', 'AS'])) {
                        throw new \LogicException('Protected widget filling preserves geometry, actions and page ownership.');
                    }
                    $kids = $field->dictionary->get('Kids');
                    $owner = $kids instanceof IndirectReference ? (string) $changes->current->record($kids)->identifier : $id;
                    $changes->assertExclusiveReferences($widget->identifier, [$owner, $after->widgetOwner($widget->identifier, $dictionary)]);
                    if (!$changes->same($previous->snapshot, $widget->snapshot)) {
                        $changes->change($widget->identifier);
                    }
                }
                if ($type->value !== 'Btn') {
                    $changes->appearance($dictionary->get('AP'), $oldDictionary->get('AP'), true);
                    $this->appearanceState($changes->current, $dictionary);
                }
            }
            ++$count;
        }
        return $count;
    }

    private function text(FrozenSignatureGraph $graph, FrozenFormField $field, ?BasicType $value, int $flags): void
    {
        if (($flags & (TextFieldFlag::RichText->value | TextFieldFlag::FileSelect->value)) !== 0
            || ($value !== null && !$value instanceof StringType) || $field->dictionary->has('RV')) {
            throw new \LogicException('Protected text filling requires direct plain text; rich text and file selection remain unclassified.');
        }
        $maximum = $graph->resolve($field->effective('MaxLen'));
        if ($maximum !== null && (!$maximum instanceof IntegerType || $maximum->integer() < 0)) {
            throw new \LogicException('Protected text MaxLen must be a nonnegative integer.');
        }
        if (($flags & TextFieldFlag::Comb->value) !== 0 && (!$maximum instanceof IntegerType || $maximum->integer() < 1 || ($flags & (TextFieldFlag::Multiline->value | TextFieldFlag::Password->value)) !== 0)) {
            throw new \LogicException('Protected comb text retains consistent length and flags.');
        }
        if ($value instanceof StringType && $maximum instanceof IntegerType
            && preg_match_all('/./us', (new TextStringDecoder())->decode($value)) > $maximum->integer()) {
            throw new \LogicException('Protected text filling preserves MaxLen.');
        }
    }

    private function choice(FrozenSignatureGraph $graph, FrozenFormField $field, ?BasicType $value, int $flags): void
    {
        $multiple = ($flags & ChoiceFieldFlag::MultiSelect->value) !== 0;
        $editable = ($flags & (ChoiceFieldFlag::Combo->value | ChoiceFieldFlag::Edit->value)) === (ChoiceFieldFlag::Combo->value | ChoiceFieldFlag::Edit->value);
        if (($flags & ChoiceFieldFlag::Edit->value) !== 0 && !$editable) {
            throw new \LogicException('Protected editable choices require Combo and Edit together.');
        }
        $options = $graph->resolve($field->effective('Opt'));
        if (!$options instanceof ArrayType || ($value instanceof ArrayType && !$multiple)
            || ($value !== null && !$value instanceof StringType && !$value instanceof ArrayType)) {
            throw new \LogicException('Protected choice filling requires fixed options and direct string values.');
        }
        $values = $value instanceof ArrayType ? $value->items : ($value === null ? [] : [$value]);
        $choices = [];
        $exports = [];
        $ambiguous = false;
        foreach ($options->items as $option) {
            $pair = $option instanceof ArrayType ? $option->items : [$option, $option];
            if (count($pair) !== 2 || !$pair[0] instanceof StringType || !$pair[1] instanceof StringType) {
                throw new \LogicException('Protected choice options are direct text strings or export/display pairs.');
            }
            $export = (new TextStringDecoder())->decode($pair[0]);
            $display = (new TextStringDecoder())->decode($pair[1]);
            if (isset($exports[$export]) && $exports[$export] !== $display) {
                $ambiguous = true;
            }
            $exports[$export] = $display;
            $choices[] = [$export, $display];
        }
        $texts = [];
        foreach ($values as $item) {
            if (!$item instanceof StringType) {
                throw new \LogicException('Protected choice selections contain direct text strings.');
            }
            $text = (new TextStringDecoder())->decode($item);
            $matches = false;
            foreach ($choices as $pair) {
                $matches = $matches || in_array($text, $pair, true);
            }
            if (!$editable && !$matches) {
                throw new \LogicException('A protected noneditable choice selects an existing option.');
            }
            $texts[] = $text;
        }
        $indices = $field->dictionary->get('I');
        $pdf20 = (new EffectivePdfVersion($graph->revision->header, $graph->catalog))->value === PdfVersion::PDF20;
        if (($indices !== null && !$indices instanceof ArrayType) || ($indices !== null && !$multiple && !$pdf20)
            || (($ambiguous && ($multiple || $pdf20)) || ($multiple && $value instanceof ArrayType && !$pdf20)) && !$indices instanceof ArrayType) {
            throw new \LogicException('Protected choice selection indices follow the document version and option ambiguity.');
        }
        if ($indices instanceof ArrayType) {
            if (count($indices->items) !== count($texts)) {
                throw new \LogicException('Protected choice indices identify exactly the selected values.');
            }
            $previous = -1;
            foreach ($indices->items as $position => $index) {
                if (!$index instanceof IntegerType || $index->integer() <= $previous || !isset($choices[$index->integer()])
                    || !in_array($texts[$position], $choices[$index->integer()], true)) {
                    throw new \LogicException('Protected choice indices are ordered, unique and agree with selected options.');
                }
                $previous = $index->integer();
            }
        }
        $top = $field->dictionary->get('TI');
        if ($top !== null && (!$top instanceof IntegerType || $top->integer() < 0 || ($choices !== [] && $top->integer() >= count($choices)))) {
            throw new \LogicException('Protected choice TI remains within the fixed option list.');
        }
    }

    private function button(FrozenSignatureGraph $graph, FrozenFormField $field, ?BasicType $value, int $flags): void
    {
        if (($flags & ButtonFieldFlag::Pushbutton->value) !== 0 || ($value !== null && !$value instanceof NameType)) {
            throw new \LogicException('Protected button filling requires a checkbox or radio name value.');
        }
        $selected = $value instanceof NameType ? $value->value : 'Off';
        $radio = ($flags & ButtonFieldFlag::Radio->value) !== 0;
        $unison = ($flags & ButtonFieldFlag::RadiosInUnison->value) !== 0;
        if (!$radio && ($flags & (ButtonFieldFlag::NoToggleToOff->value | ButtonFieldFlag::RadiosInUnison->value)) !== 0) {
            throw new \LogicException('Protected checkbox flags cannot enable radio-only behaviours.');
        }
        $on = 0;
        foreach ($field->widgets as $index => $widget) {
            $dictionary = $widget->dictionary ?? throw new \LogicException('Protected widgets are dictionaries.');
            $appearance = $graph->resolve($dictionary->get('AP'));
            $normal = $appearance instanceof DictionaryType ? $graph->resolve($appearance->get('N')) : null;
            $state = $dictionary->get('AS');
            if (!$normal instanceof DictionaryType || !$normal->has('Off') || count(iterator_to_array($normal->entries(), false)) !== 2
                || !$state instanceof NameType || !$normal->has($state->value)) {
                throw new \LogicException('Protected button widgets select an existing Off/on appearance.');
            }
            if ($field->effective('Opt') !== null && !$normal->has((string) $index)) {
                throw new \LogicException('Protected button export options preserve indexed appearance states.');
            }
            if ($state->value !== 'Off') {
                if ($state->value !== $selected) {
                    throw new \LogicException('Protected button AS agrees with the field V.');
                }
                ++$on;
            }
            if ((!$radio || $unison) && $normal->has($selected) && $state->value !== $selected) {
                throw new \LogicException('Protected widgets representing the selected button value agree.');
            }
        }
        if (($radio && !$unison && $on > 1) || ($field->widgets !== [] && $selected !== 'Off' && $on === 0)
            || ($radio && ($flags & ButtonFieldFlag::NoToggleToOff->value) !== 0 && $selected === 'Off')) {
            throw new \LogicException('Protected radio/check box selection preserves its historical flags.');
        }
    }

    private function appearanceState(FrozenSignatureGraph $graph, DictionaryType $widget): void
    {
        $appearance = $graph->resolve($widget->get('AP'));
        $state = $widget->get('AS');
        if ($state !== null && !$state instanceof NameType) {
            throw new \LogicException('Protected widget appearance states are direct names.');
        }
        if (!$appearance instanceof DictionaryType) {
            if ($state !== null) {
                throw new \LogicException('A protected widget state needs an appearance dictionary.');
            }
            return;
        }
        foreach (['N', 'R', 'D'] as $key) {
            $entry = $appearance->get($key);
            if ($entry instanceof IndirectReference && $graph->record($entry)->snapshot instanceof RevisionStreamSnapshot) {
                continue;
            }
            $value = $graph->resolve($entry);
            if ($value instanceof DictionaryType && (!$state instanceof NameType || !$value->has($state->value))) {
                throw new \LogicException('Protected widget AS selects a declared appearance state.');
            }
        }
    }
}
