<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\CatalogComponent;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Explicit finite page list; unlike an article thread, its ends do not form a ring. */
final class PresentationSequence implements CatalogComponent
{
    /** @var list<NavigationNode> */
    private array $nodes = [];
    private ?PageObject $page = null;
    private ?NavigationNode $primary = null;

    public function __construct(private readonly NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function addNode(NavigationNode $node): self
    {
        if ($this->page !== null) {
            throw new \LogicException('Configure the whole presentation list before binding its page.');
        }
        if (count($this->nodes) >= $this->limits->maxNodes) {
            throw new \LengthException('The presentation list exceeds its explicit node budget.');
        }
        $node->attachTo($this);
        $this->nodes[] = $node;
        return $this;
    }

    /** @return list<NavigationNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function pageEntry(?NavigationNode $primary = null): Entry
    {
        $primary ??= $this->nodes[0] ?? throw new \LogicException('A presentation sequence needs a primary node.');
        if (!in_array($primary, $this->nodes, true)) {
            throw new \InvalidArgumentException('The primary node must belong to this page sequence.');
        }
        return new Entry(new NameType('PresSteps'), new IndirectReference($primary));
    }

    public function bindToPage(PageObject $page): self
    {
        if ($this->page !== null) {
            throw new \InvalidArgumentException('A presentation sequence belongs to one explicit page.');
        }
        $primary = $page->get('PresSteps');
        if (!$primary instanceof IndirectReference || !$primary->target() instanceof NavigationNode || !in_array($primary->target(), $this->nodes, true)) {
            throw new \InvalidArgumentException('The actual page PresSteps must reference a node in this list.');
        }
        $this->primary = $primary->target();
        $this->page = $page;
        return $this;
    }

    public function enter(NavigationDirection $direction): NavigationRequest
    {
        return ($this->primary ?? throw new \LogicException('Bind the presentation primary to its page first.'))->request($direction);
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $page = $this->page ?? throw new \LogicException('Bind the presentation sequence to its page.');
        $pages = new CatalogPages($catalog, $this->limits);
        $pages->index($page);
        $references = 0;
        foreach ($pages->pages as $candidate) {
            $primary = $candidate->get('PresSteps');
            if ($primary instanceof IndirectReference && in_array($primary->target(), $this->nodes, true)) {
                ++$references;
                if ($candidate !== $page || $primary->target() !== $this->primary) {
                    throw new \InvalidArgumentException('PresSteps must identify this list only from its explicit owning page.');
                }
            }
        }
        if ($references !== 1) {
            throw new \InvalidArgumentException('A presentation list has exactly one actual owning page PresSteps.');
        }
        $effects = new NavigationActionEffects($this->limits);
        foreach ($this->nodes as $node) {
            if ($node->next() !== null && $node->forward !== null && $effects->leavesPage($node->forward, $page, $catalog)) {
                throw new \InvalidArgumentException('A forward action leaving the page should not also declare a Next node.');
            }
            if ($node->previous() !== null && $node->backward !== null && $effects->leavesPage($node->backward, $page, $catalog)) {
                throw new \InvalidArgumentException('A backward action leaving the page should not also declare a Prev node.');
            }
        }
    }

    private function __clone(): void
    {
    }
}
