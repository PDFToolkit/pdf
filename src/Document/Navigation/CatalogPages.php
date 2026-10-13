<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Read-only, document-local page identities, in tree order, from the actual catalogue. */
final readonly class CatalogPages
{
    /** @var list<DictionaryType> */
    public array $pages;

    public function __construct(DictionaryType $catalog, NavigationLimits $limits = new NavigationLimits())
    {
        $root = $catalog->get('Pages');
        if (!$root instanceof IndirectReference || !$root->target() instanceof DictionaryType) {
            throw new \InvalidArgumentException('Navigation requires the actual catalogue Pages reference.');
        }
        $pending = [[$root->target(), 0]];
        $seen = new \SplObjectStorage();
        $pages = [];
        while ($pending !== []) {
            [$node, $depth] = array_pop($pending);
            if ($depth > $limits->maxDepth || count($seen) >= $limits->maxNodes || $seen->offsetExists($node)) {
                throw new \InvalidArgumentException('The navigation page tree repeats an identity or exceeds its explicit budget.');
            }
            $seen->offsetSet($node);
            $type = $node->get('Type');
            if (!$type instanceof NameType) {
                throw new \InvalidArgumentException('A navigation page-tree node requires its Type.');
            }
            if ($type->value === 'Page') {
                $pages[] = $node;
                continue;
            }
            $kids = $node->get('Kids');
            $kids = $kids instanceof IndirectReference ? $kids->target() : $kids;
            if ($type->value !== 'Pages' || !$kids instanceof ArrayType) {
                throw new \InvalidArgumentException('A navigation branch requires Pages and a Kids array.');
            }
            foreach (array_reverse($kids->items) as $kid) {
                if (!$kid instanceof IndirectReference || !$kid->target() instanceof DictionaryType) {
                    throw new \InvalidArgumentException('Page-tree Kids must be indirect dictionary references.');
                }
                $pending[] = [$kid->target(), $depth + 1];
            }
        }
        $this->pages = $pages;
    }

    public function index(DictionaryType $page): int
    {
        $index = array_search($page, $this->pages, true);
        if ($index === false) {
            throw new \InvalidArgumentException('The navigation target is not a page of this document.');
        }
        return $index;
    }
}
