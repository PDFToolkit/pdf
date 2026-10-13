<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Effective PDF identities and frozen page dictionaries, never old builder properties. */
final readonly class NavigationPageIndex
{
    /** @var list<array{BasicType, DictionaryType}> */
    public array $pages;
    /** @var array<string, int> */
    private array $indices;

    public function __construct(public DocumentGraphContext $graph, NavigationLimits $limits = new NavigationLimits())
    {
        $root = $graph->catalog->get('Pages');
        if (!$root instanceof IndirectReference) {
            throw new \InvalidArgumentException('Navigation requires an effective catalogue Pages reference.');
        }
        $pending = [[$root, 0]];
        $seen = [];
        $pages = [];
        $indices = [];
        while ($pending !== []) {
            [$reference, $depth] = array_pop($pending);
            $key = $this->key($reference);
            if (isset($seen[$key]) || count($seen) >= $limits->maxNodes || $depth > $limits->maxDepth) {
                throw new \InvalidArgumentException('Effective navigation pages repeat an identity or exceed their budget.');
            }
            $seen[$key] = true;
            $dictionary = $graph->dictionary($reference);
            $type = $dictionary?->get('Type');
            if (!$dictionary instanceof DictionaryType || !$type instanceof NameType) {
                throw new \InvalidArgumentException('An effective page-tree node must be a typed dictionary.');
            }
            if ($type->value === 'Page') {
                $indices[$key] = count($pages);
                $pages[] = [$reference, $dictionary];
                continue;
            }
            $kidsValue = $dictionary->get('Kids');
            $kids = $kidsValue === null ? null : $graph->resolve($kidsValue);
            if ($type->value !== 'Pages' || !$kids instanceof ArrayType) {
                throw new \InvalidArgumentException('An effective page branch requires its Kids array.');
            }
            foreach (array_reverse($kids->items) as $kid) {
                if (!$kid instanceof IndirectReference) {
                    throw new \InvalidArgumentException('Effective page Kids must be indirect references.');
                }
                $pending[] = [$kid, $depth + 1];
            }
        }
        $this->pages = $pages;
        $this->indices = $indices;
    }

    public function key(BasicType $value): string
    {
        $identifier = $this->graph->identifier($value);
        return $identifier === null ? 'direct:' . spl_object_id($this->graph->resolve($value)) : (string) $identifier;
    }

    public function index(BasicType $reference): int
    {
        return $this->indices[$this->key($reference)] ?? throw new \InvalidArgumentException('The destination or association is not an effective page of this document.');
    }
}
