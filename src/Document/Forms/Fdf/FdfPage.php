<?php

declare(strict_types=1);

namespace PDF\Document\Forms\Fdf;

use PDF\Specification\Definition\FDFPageDictionaryTable248Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IndirectReference;

/** FDF page addition declaration, not a PDF page (ISO p.471). */
final class FdfPage extends SchemaDictionary
{
    public function __construct(ArrayType $templates, ?DictionaryType $information = null)
    {
        foreach ($templates->items as $template) {
            $template = $template instanceof IndirectReference ? $template->target() : $template;
            if (!$template instanceof FdfTemplate) {
                throw new \InvalidArgumentException('FDF page Templates contains explicit template dictionaries.');
            }
        }
        $entries = [new Entry(new NameType('Templates'), $templates)];
        if ($information !== null) {
            $entries[] = new Entry(new NameType('Info'), $information);
        }
        parent::__construct(new FDFPageDictionaryTable248Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('FDF page addition data is constructor-controlled.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('FDF page addition data is constructor-controlled.');
    }
}
