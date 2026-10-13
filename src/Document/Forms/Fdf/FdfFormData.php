<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Specification\Definition\FDFDictionaryTable243Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\IntegerType;

/** FDF content including optional document association, annotations, templates and opaque scripts. */
final class FdfFormData extends SchemaDictionary
{
    public function __construct(?ArrayType $fields = null, StringType|DictionaryType|null $associatedFile = null, Entry ...$entries)
    {
        $managed = [];
        if ($fields !== null) {
            foreach ($fields->items as $field) {
                $field = $field instanceof IndirectReference ? $field->target() : $field;
                if (!$field instanceof FdfField) {
                    throw new \InvalidArgumentException('FDF Fields contains FDF root fields.');
                }
            }
            $managed[] = new Entry(new NameType('Fields'), $fields);
        }
        if ($associatedFile !== null) {
            $managed[] = new Entry(new NameType('F'), $associatedFile);
        }
        parent::__construct(new FDFDictionaryTable243Definition(), ...[...$managed, ...$entries]);
    }

    public function validate(): void
    {
        parent::validate();
        if ($this->has('Pages') && ($this->has('Fields') || $this->has('Status'))) {
            throw new \InvalidArgumentException('FDF Pages cannot occur together with Fields or Status.');
        }
        $annotations = $this->get('Annots');
        foreach ($annotations instanceof ArrayType ? $annotations->items : [] as $annotation) {
            $annotation = $annotation instanceof IndirectReference ? $annotation->target() : $annotation;
            if (!$annotation instanceof DictionaryType) {
                throw new \InvalidArgumentException('FDF Annots contains annotation dictionaries.');
            }
            $page = $annotation->get('Page');
            $subtype = $annotation->get('Subtype');
            if (!$page instanceof IntegerType || $page->integer() < 0 || !$subtype instanceof NameType || in_array($subtype->value, ['Link', 'Movie', 'Widget', 'PrinterMark', 'Screen', 'TrapNet'], true)) {
                throw new \InvalidArgumentException('FDF annotations require a nonnegative Page index and a permitted annotation subtype.');
            }
        }
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('FDF data is supplied explicitly in its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('FDF data is supplied explicitly in its constructor.');
    }
}
