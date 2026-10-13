<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\TerminalField;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\TextFieldFlag;
use PDF\Document\Forms\FieldTextStream;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Snapshot exactly the fields selected by the caller; submission actions and networking are separate. */
final readonly class FdfExporter
{
    public function __construct(private FieldTree $tree)
    {
    }

    public function export(TerminalField ...$selected): FdfFormData
    {
        $this->tree->validate();
        $root = new FdfExportNode('');
        foreach ($selected as $field) {
            if ($field->tree() !== $this->tree) {
                throw new \InvalidArgumentException('Exported fields must belong to the selected form.');
            }
            $value = $field->effective('V');
            if ($value instanceof IndirectReference && $value->target() instanceof FieldTextStream) {
                $value = $value->target()->text;
            }
            if ($field instanceof TextField && ($field->flags() & TextFieldFlag::FileSelect->value) !== 0 && $value instanceof TextString) {
                // ISO p.451: FDF file-select values identify the selected file; no file is read.
                $value = new DictionaryType(new Entry(new NameType('Type'), new NameType('Filespec')), new Entry(new NameType('UF'), $value));
            }
            $root->insert(explode('.', $field->qualifiedName()), $value);
        }
        return new FdfFormData(new ArrayType(...$root->fields()));
    }
}
