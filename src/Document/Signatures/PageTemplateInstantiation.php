<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Explicit append operation; the writer independently proves the actual emitted template delta. */
final readonly class PageTemplateInstantiation
{
    public DictionaryType $page;
    public FileBody $body;

    public function __construct(public FileRevision $source, public NameType $name)
    {
        $graph = new FrozenSignatureGraph($source);
        $template = (new FrozenPageTemplates($graph))->named($name)->dictionary
            ?? throw new \LogicException('A named template is a dictionary.');
        if ($template->has('Annots')) {
            throw new \LogicException('Template annotation and field cloning requires a separate explicit operation.');
        }
        $root = $graph->catalog->get('Pages');
        if (!$root instanceof IndirectReference) {
            throw new \LogicException('The source page tree has an indirect root.');
        }
        $record = $graph->record($root);
        $dictionary = $record->dictionary ?? throw new \LogicException('The source page-tree root is a dictionary.');
        $kids = $graph->resolve($dictionary->get('Kids'));
        $count = $dictionary->get('Count');
        if (!$kids instanceof ArrayType || !$count instanceof IntegerType || $count->integer() < 0 || $count->integer() === PHP_INT_MAX) {
            throw new \LogicException('The source page count and children are bounded explicit values.');
        }
        $this->page = $template->with(new Entry(new NameType('Type'), new NameType('Page')))
            ->with(new Entry(new NameType('Parent'), $root))
            ->with(new Entry(new NameType('TemplateInstantiated'), $name));
        $this->body = new FileBody(new IndirectObject(
            $dictionary->with(new Entry(new NameType('Kids'), new ArrayType(...[...$kids->items, new IndirectReference($this->page)])))
                ->with(new Entry(new NameType('Count'), new IntegerType($count->integer() + 1))),
            $record->identifier
        ));
    }

    public function update(?UsageRightsAuthorization $authorization = null): IncrementalUpdate
    {
        return new IncrementalUpdate($this->source, $this->body, null, null, ...($authorization === null ? [] : [$authorization]));
    }
}
