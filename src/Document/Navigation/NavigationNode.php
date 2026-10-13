<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\NavigationNodeDictionaryTable163Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** A page presentation step with explicit actions and derived reciprocal links. */
final class NavigationNode extends SchemaDictionary implements VersionedComponent
{
    private ?PresentationSequence $sequence = null;

    public function __construct(public readonly ?DictionaryType $forward = null, public readonly ?DictionaryType $backward = null, public readonly ?PresentationDuration $duration = null)
    {
        $entries = [new Entry(new NameType('Type'), new NameType('NavNode'))];
        foreach (['NA' => $forward, 'PA' => $backward] as $key => $action) {
            if ($action !== null) {
                $type = $action->get('S');
                if (!$type instanceof NameType) {
                    throw new \InvalidArgumentException('A presentation action requires its S name.');
                }
                $entries[] = new Entry(new NameType($key), $action);
            }
        }
        if ($duration !== null) {
            $entries[] = new Entry(new NameType('Dur'), $duration);
        }
        parent::__construct(new NavigationNodeDictionaryTable163Definition(), ...$entries);
    }

    /** @internal Attach through PresentationSequence::addNode(). */
    public function attachTo(PresentationSequence $sequence): void
    {
        if ($this->sequence !== null) {
            throw new \InvalidArgumentException('A navigation node belongs to one page sequence.');
        }
        $this->sequence = $sequence;
    }

    public function sequence(): PresentationSequence
    {
        return $this->sequence ?? throw new \LogicException('Attach the navigation node to its explicit page sequence.');
    }

    public function next(): ?self
    {
        $nodes = $this->sequence()->nodes();
        $index = array_search($this, $nodes, true);
        if ($index === false) {
            throw new \LogicException('The navigation sequence must contain its node.');
        }
        return $nodes[$index + 1] ?? null;
    }

    public function previous(): ?self
    {
        $nodes = $this->sequence()->nodes();
        $index = array_search($this, $nodes, true);
        if ($index === false) {
            throw new \LogicException('The navigation sequence must contain its node.');
        }
        return $index === 0 ? null : $nodes[$index - 1];
    }

    public function entries(): iterable
    {
        if (($next = $this->next()) !== null) {
            yield new Entry(new NameType('Next'), new IndirectReference($next));
        }
        if (($previous = $this->previous()) !== null) {
            yield new Entry(new NameType('Prev'), new IndirectReference($previous));
        }
        yield from parent::entries();
    }

    public function request(NavigationDirection $direction): NavigationRequest
    {
        return $direction === NavigationDirection::Backward ? new NavigationRequest($this, $direction, $this->backward, $this->previous()) : new NavigationRequest($this, $direction, $this->forward, $this->next());
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $this->sequence()->validateCatalog($catalog);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF15, 'Sub-page presentation navigation');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('A navigation node derives its links from its sequence.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('A navigation node derives its links from its sequence.');
    }

    private function __clone(): void
    {
    }
}
