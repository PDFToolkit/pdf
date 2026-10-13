<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphComponent;
use PDF\Document\DocumentGraphContext;
use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\GoToActionTable199Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\IndirectReference;

/** Local GoTo composition only, ISO12.6.4.2/Table199; no action execution. */
final class GoToDestination extends SchemaDictionary implements DocumentGraphComponent, VersionedComponent
{
    public function __construct(public readonly LocalDestination|NamedDestination $destination, public readonly DictionaryType|ArrayType|IndirectReference|null $next = null)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('Action')), new Entry(new NameType('S'), new NameType('GoTo')), new Entry(new NameType('D'), $destination instanceof NamedDestination ? $destination->toObject() : $destination)];
        if ($next !== null) {
            $entries[] = new Entry(new NameType('Next'), $next);
        }
        parent::__construct(new GoToActionTable199Definition(), ...$entries);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $this->destination->validateCatalog($catalog);
    }

    public function validateGraph(DocumentGraphContext $graph): void
    {
        if (!$graph->isCurrent($this) || !$graph->isReachable($this)) {
            return;
        }
        $dictionary = $graph->dictionary($this);
        $value = $dictionary?->get('D');
        $destination = $value === null ? null : $graph->resolve($value);
        // The global document lookup validates ownership and namespace membership once.
        if ($destination instanceof ArrayType) {
            new ExplicitDestinationParameters($destination);
        } elseif (!$destination instanceof NameType && !$destination instanceof StringType) {
            throw new \InvalidArgumentException('An effective GoTo action requires its local destination.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF11, 'Local GoTo action');
        if ($this->next !== null) {
            $version->require(PdfVersion::PDF12, 'Next action chain');
        }
        $this->destination->validateVersion($version);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('A GoToDestination composes its explicit destination.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('A GoToDestination composes its explicit destination.');
    }
}
