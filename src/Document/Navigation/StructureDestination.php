<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Structure\StructureElement;
use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** Local structure identity, ISO 32000-2 12.3.2.3, physical page 458. */
final class StructureDestination extends ArrayType implements VersionedComponent
{
    public function __construct(public readonly StructureElement $element, public readonly DestinationView $view)
    {
        parent::__construct(new IndirectReference($element), $view->fit, ...$view->coordinates);
    }

    public function page(DictionaryType|DocumentGraphContext $document): IndirectReference
    {
        return (new StructureDestinationResolver($document))->page($this);
    }

    public function local(DictionaryType|DocumentGraphContext $document): LocalDestination
    {
        $page = $this->page($document)->target();
        if (!$page instanceof \PDF\Syntax\DocumentStructure\PageTree\PageObject) {
            throw new \LogicException('A native local view requires a native PageObject; use the graph resolver for frozen dictionaries.');
        }
        $array = new ArrayType(new IndirectReference($page), $this->view->fit, ...$this->view->coordinates);
        return new LocalDestination(new ExplicitPageDestination($array));
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Structure destinations');
    }
}
