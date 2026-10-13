<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** An application names an explicitly supplied stream; validation binds it to the actual field widgets. */
final readonly class SignatureAppearanceSelection
{
    public function __construct(public TextString $name, public IndirectReference $normalAppearance)
    {
    }

    public function validate(FrozenSignatureGraph $graph, FrozenSignatureField $field): void
    {
        $selected = $graph->record($this->normalAppearance);
        if ($selected->objectKind !== 'stream') {
            throw new \InvalidArgumentException('A named signature appearance identifies a real appearance stream.');
        }
        $widgets = [];
        $subtype = $field->dictionary->get('Subtype');
        if ($subtype instanceof NameType && $subtype->value === 'Widget') {
            $widgets[] = $field->dictionary;
        }
        $kids = $graph->resolve($field->dictionary->get('Kids'));
        foreach ($kids instanceof ArrayType ? $kids->items : [] as $kid) {
            $dictionary = $graph->resolve($kid);
            $type = $dictionary instanceof DictionaryType ? $dictionary->get('Subtype') : null;
            if ($dictionary instanceof DictionaryType && $type instanceof NameType && $type->value === 'Widget') {
                $widgets[] = $dictionary;
            }
        }
        if ($widgets === []) {
            throw new \LogicException('The named appearance must be attached to an actual signature widget; invisible fields have no such proof.');
        }
        foreach ($widgets as $widget) {
            $appearance = $graph->resolve($widget->get('AP'));
            $normal = $appearance instanceof DictionaryType ? $appearance->get('N') : null;
            if ($normal instanceof IndirectReference && $graph->record($normal)->objectKind === 'dictionary') {
                $normal = $graph->resolve($normal);
            }
            if ($normal instanceof DictionaryType) {
                $state = $widget->get('AS');
                $normal = $state instanceof NameType ? $normal->get($state) : null;
            }
            if (!$normal instanceof IndirectReference || (string) $graph->record($normal)->identifier !== (string) $selected->identifier) {
                throw new \InvalidArgumentException('The required named appearance is the selected normal appearance actually attached to every field widget.');
            }
        }
    }
}
