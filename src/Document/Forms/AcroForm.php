<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Document\CatalogComponent;
use PDF\Specification\Definition\InteractiveFormDictionaryTable218Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\StreamObject;

/** ISO 12.7.2, p.439: explicit roots, defaults and calculation order. */
final class AcroForm extends SchemaDictionary implements CatalogComponent
{
    public function __construct(public readonly FieldTree $fields, public readonly ?CalculationOrder $calculationOrder = null, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key->value, ['Fields', 'NeedAppearances', 'CO', 'DA', 'DR', 'Q'], true)) {
                throw new \InvalidArgumentException('Form hierarchy and defaults are supplied by its constructor.');
            }
        }
        parent::__construct(new InteractiveFormDictionaryTable218Definition(), ...$entries);
    }

    public function entries(): iterable
    {
        yield new Entry(new NameType('Fields'), new ArrayType(...array_map(static fn (Field $field) => new IndirectReference($field), $this->fields->roots())));
        yield new Entry(new NameType('NeedAppearances'), $this->fields->needAppearances);
        foreach (['DA', 'DR', 'Q'] as $key) {
            $value = $this->fields->defaultValue($key);
            if ($value !== null) {
                yield new Entry(new NameType($key), $value);
            }
        }
        if ($this->calculationOrder !== null) {
            yield new Entry(new NameType('CO'), $this->calculationOrder->object());
        }
        yield from parent::entries();
    }

    public function validate(): void
    {
        parent::validate();
        $this->fields->validate();
        $signatureFlags = $this->get('SigFlags');
        if ($signatureFlags !== null && (!$signatureFlags instanceof IntegerType || $signatureFlags->integer() < 0 || $signatureFlags->integer() > 3)) {
            throw new \InvalidArgumentException('AcroForm SigFlags uses only SignaturesExist and AppendOnly bits.');
        }
        $xfa = $this->get('XFA');
        $xfa = $xfa instanceof IndirectReference ? $xfa->target() : $xfa;
        if ($xfa instanceof ArrayType) {
            if (count($xfa) % 2 !== 0) {
                throw new \InvalidArgumentException('XFA arrays alternate packet names and XML streams.');
            }
            foreach ($xfa->items as $index => $item) {
                if ($index % 2 === 0 ? !$item instanceof StringType : (!$item instanceof IndirectReference || !$item->target() instanceof StreamObject)) {
                    throw new \InvalidArgumentException('XFA arrays contain text packet names and indirect XML stream references.');
                }
            }
        }
        $calculated = [];
        foreach ($this->fields->fields() as $field) {
            $actions = $field->get('AA');
            $actions = $actions instanceof IndirectReference ? $actions->target() : $actions;
            if ($actions instanceof DictionaryType && $actions->has('C')) {
                $calculated[] = $field;
            }
        }
        $order = $this->calculationOrder === null ? [] : $this->calculationOrder->fields;
        foreach ($order as $field) {
            if ($field->tree() !== $this->fields || !in_array($field, $calculated, true)) {
                throw new \InvalidArgumentException('CO refers only to this form fields with calculation actions.');
            }
        }
        foreach ($calculated as $field) {
            if (!in_array($field, $order, true)) {
                throw new \InvalidArgumentException('A field calculation action requires membership in CO.');
            }
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Supply form defaults and optional entries in the constructor.');
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $this->validate();
        $root = $catalog->get('Pages');
        if (!$root instanceof IndirectReference || !$root->target() instanceof DictionaryType) {
            throw new \InvalidArgumentException('An AcroForm catalog needs its indirect Pages root.');
        }
        $pending = [$root->target()];
        $pages = new \SplObjectStorage();
        $visited = new \SplObjectStorage();
        while ($pending !== []) {
            $node = array_shift($pending);
            if ($visited->offsetExists($node)) {
                throw new \InvalidArgumentException('The form catalog page tree has a cycle or duplicated page ownership.');
            }
            $visited->offsetSet($node);
            $type = $node->get('Type');
            if ($type instanceof NameType && $type->value === 'Page') {
                $pages->offsetSet($node);
                continue;
            }
            $kids = $node->get('Kids');
            if (!$type instanceof NameType || $type->value !== 'Pages' || !$kids instanceof ArrayType) {
                throw new \InvalidArgumentException('The form catalog has an invalid page-tree node.');
            }
            foreach ($kids->items as $kid) {
                if (!$kid instanceof IndirectReference || !$kid->target() instanceof DictionaryType) {
                    throw new \InvalidArgumentException('Page-tree Kids must reference page dictionaries.');
                }
                $pending[] = $kid->target();
            }
        }
        foreach ($this->fields->fields() as $field) {
            if ($field instanceof TerminalField) {
                foreach ($field->widgets() as $widget) {
                    if ($widget->page() === null || !$pages->offsetExists($widget->page())) {
                        throw new \InvalidArgumentException('Every form widget page must belong to the actual catalog Pages tree.');
                    }
                }
            }
        }
        $requiresAdobe = false;
        foreach ($this->fields->fields() as $field) {
            $lock = $field->get('Lock');
            $lock = $lock instanceof IndirectReference ? $lock->target() : $lock;
            if ($field instanceof BarcodeField || ($lock instanceof DictionaryType && $lock->has('P'))) {
                $requiresAdobe = true;
            }
        }
        if ($requiresAdobe) {
            $extensions = $catalog->get('Extensions');
            $adobe = $extensions instanceof DictionaryType ? $extensions->get('ADBE') : null;
            $base = $adobe instanceof DictionaryType ? $adobe->get('BaseVersion') : null;
            $level = $adobe instanceof DictionaryType ? $adobe->get('ExtensionLevel') : null;
            if (!$base instanceof NameType || $base->value !== '1.7' || !$level instanceof IntegerType || $level->integer() < 3) {
                throw new \InvalidArgumentException('Adobe barcode fields and signature lock P require direct ADBE BaseVersion 1.7, ExtensionLevel 3 or higher.');
            }
        }
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Supply form defaults and optional entries in the constructor.');
    }
}
