<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;

/** One shared validation of the eight local Table151 parameter forms. */
final readonly class ExplicitDestinationParameters
{
    public IndirectReference $reference;
    public NameType $fit;
    /** @var list<NumericType|NullType> */
    public array $coordinates;

    public function __construct(ArrayType $destination)
    {
        $items = $destination->items;
        $page = $items[0] ?? null;
        $fit = $items[1] ?? null;
        if (!$page instanceof IndirectReference || !$fit instanceof NameType) {
            throw new \InvalidArgumentException('A local destination requires an indirect page and a fit name.');
        }
        $view = new DestinationView($fit, ...array_slice($items, 2));
        $this->reference = $page;
        $this->fit = $fit;
        $this->coordinates = $view->coordinates;
    }

    /** The builder view; effective snapshot ownership is checked through DocumentGraphContext. */
    public function page(): PageObject
    {
        $page = $this->reference->target();
        return $page instanceof PageObject ? $page : throw new \InvalidArgumentException('A native local destination builder requires its explicit PageObject.');
    }
}
