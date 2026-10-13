<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Functions\FunctionObject;
use PDF\Graphics\Halftones\HalftoneObject;
use PDF\Specification\Definition\GraphicsStateParameterDictionaryTable58Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

/** Native ExtGState declaration with coupled function, mask and numeric constraints. */
final class GraphicsStateParameters extends SchemaDictionary implements \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF12, 'ExtGState parameter dictionary');
    }

    public const string TABLE = '58';

    public function __construct(Entry ...$entries)
    {
        foreach ($entries as $entry) {
            $this->validateValue($entry);
        }
        parent::__construct(new GraphicsStateParameterDictionaryTable58Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        $this->validateValue($entry);
        return parent::with($entry);
    }

    public function validate(): void
    {
        parent::validate();
        foreach ($this->entries() as $entry) {
            $this->validateValue($entry);
        }
    }

    /**
     * The PDF precedence rules do not depend on dictionary insertion order.
     * @return list<Entry>
     */
    public function effectiveEntries(): array
    {
        $entries = [];
        foreach ($this->entries() as $entry) {
            if ($entry->key->value !== 'Type' && !(in_array($entry->key->value, ['BG', 'UCR', 'TR'], true) && $this->has($entry->key->value . '2'))) {
                $entries[] = $entry;
            }
        }
        $overprint = $this->get('OP');
        if ($overprint !== null && !$this->has('op')) {
            $entries[] = new Entry(new NameType('op'), $overprint);
        }
        return $entries;
    }

    private function validateValue(Entry $entry): void
    {
        $key = $entry->key->value;
        $value = $entry->value instanceof IndirectReference ? $entry->value->target() : $entry->value;
        if (in_array($key, ['LW', 'ML', 'FL', 'SM', 'CA', 'ca'], true)) {
            $minimum = $key === 'ML' ? 1 : 0;
            $maximum = $key === 'FL' ? 100 : (in_array($key, ['SM', 'CA', 'ca'], true) ? 1 : null);
            if (!$value instanceof NumericType || $value->value < $minimum || ($maximum !== null && $value->value > $maximum)) {
                throw new \InvalidArgumentException('ExtGState /' . $key . ' is outside its prescribed numeric range.');
            }
        }
        if (in_array($key, ['LC', 'LJ', 'OPM'], true) && (!$value instanceof IntegerType || $value->integer() < 0 || $value->integer() > ($key === 'OPM' ? 1 : 2))) {
            throw new \InvalidArgumentException('ExtGState line cap/join/overprint codes must have a defined value.');
        }
        if ($key === 'D') {
            if (!$value instanceof ArrayType || count($value) !== 2 || !$value->items[0] instanceof ArrayType || !$value->items[1] instanceof IntegerType) {
                throw new \InvalidArgumentException('ExtGState D requires [dashArray integerPhase] (table58).');
            }
            new LineDashPattern($value->items[0], $value->items[1]);
        }
        if ($key === 'Font') {
            $font = $value instanceof ArrayType && ($value->items[0] ?? null) instanceof IndirectReference ? $value->items[0]->target() : null;
            $type = $font instanceof DictionaryType ? $font->get('Type') : null;
            if (!$value instanceof ArrayType || count($value) !== 2 || !$type instanceof NameType || $type->value !== 'Font' || !$value->items[1] instanceof NumericType) {
                throw new \InvalidArgumentException('ExtGState Font requires an indirect font dictionary and numeric text-space size.');
            }
        }
        if ($key === 'RI' && (!$value instanceof NameType || RenderingIntent::tryFrom($value->value) === null)) {
            throw new \InvalidArgumentException('Native rendering intent must be one of the four PDF-defined intents.');
        }
        if (in_array($key, ['BG', 'BG2', 'UCR', 'UCR2'], true)) {
            if ($value instanceof NameType && str_ends_with($key, '2') && $value->value === 'Default') {
                return;
            }
            $this->scalar($value);
        }
        if (in_array($key, ['TR', 'TR2'], true)) {
            if ($value instanceof NameType && ($value->value === 'Identity' || ($key === 'TR2' && $value->value === 'Default'))) {
                return;
            }
            if ($value instanceof ArrayType) {
                if (count($value) !== 4) {
                    throw new \InvalidArgumentException('A transfer array requires four process-component functions.');
                }
                foreach ($value->items as $function) {
                    $this->scalar($function instanceof IndirectReference ? $function->target() : $function);
                }
            } else {
                $this->scalar($value);
            }
        }
        if ($key === 'HT' && !$value instanceof HalftoneObject && !($value instanceof NameType && $value->value === 'Default')) {
            throw new \InvalidArgumentException('Native HT requires a validated halftone or Default.');
        }
        if ($key === 'SMask' && !$value instanceof SoftMask && !($value instanceof NameType && $value->value === 'None')) {
            throw new \InvalidArgumentException('Native SMask requires a validated soft-mask dictionary or None.');
        }
        if ($key === 'BM') {
            if (!$value instanceof NameType && !$value instanceof ArrayType) {
                throw new \InvalidArgumentException('Blend mode must be a name or ordered array of names.');
            }
            if ($value instanceof ArrayType) {
                foreach ($value->items as $name) {
                    if (!$name instanceof NameType) {
                        throw new \InvalidArgumentException('Blend fallback arrays contain direct names.');
                    }
                }
            }
        }
    }

    private function scalar(BasicType $value): void
    {
        if (!$value instanceof FunctionObject) {
            throw new \InvalidArgumentException('Native colour-function entries need an explicit FunctionObject.');
        }
        new ScalarColourFunction($value);
    }
}
