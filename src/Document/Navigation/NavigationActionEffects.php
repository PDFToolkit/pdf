<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StringType;

/** Inspect declared navigation targets only. Script/runtime effects are not inferred. */
final readonly class NavigationActionEffects
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function leavesPage(DictionaryType $action, PageObject $page, DictionaryType $catalog): bool
    {
        $pending = [$action];
        $seen = new \SplObjectStorage();
        $pages = new CatalogPages($catalog, $this->limits);
        $index = $pages->index($page);
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($seen->offsetExists($current)) {
                // ISO12.6.2 discusses reader recovery from repeated action identities.
                continue;
            }
            if (count($seen) >= $this->limits->maxNodes) {
                throw new \LengthException('The declared navigation action graph exceeds its explicit budget.');
            }
            $seen->offsetSet($current);
            $type = $current->get('S');
            if (!$type instanceof NameType) {
                throw new \InvalidArgumentException('A navigation action declaration needs its S name.');
            }
            if ($type->value === 'GoToR' || $type->value === 'GoToE') {
                return true;
            }
            if ($type->value === 'GoTo') {
                $destination = $current->get('D');
                $destination = $destination instanceof IndirectReference ? $destination->target() : $destination;
                $structure = $current->get('SD');
                $structure = $structure instanceof IndirectReference ? $structure->target() : $structure;
                $structure = $structure instanceof NullType ? null : $structure;
                if ($destination instanceof NameType || $destination instanceof StringType) {
                    $structure ??= (new DestinationLookup($this->limits))->structure($destination, $catalog);
                    $destination = (new DestinationLookup($this->limits))->find($destination, $catalog);
                }
                $reference = $structure instanceof ArrayType ? (new StructureDestinationResolver($catalog, $this->limits))->page($structure) : ($destination instanceof ArrayType ? ($destination->items[0] ?? null) : null);
                if (!$reference instanceof IndirectReference || !$reference->target() instanceof DictionaryType) {
                    throw new \InvalidArgumentException('A declared local GoTo needs a local page destination.');
                }
                $targetIndex = $pages->index($reference->target());
                if ($targetIndex !== $index) {
                    return true;
                }
            }
            if ($type->value === 'Named') {
                $name = $current->get('N');
                if ($name instanceof NameType) {
                    $targetIndex = match ($name->value) {
                        'FirstPage' => 0,
                        'LastPage' => count($pages->pages) - 1,
                        'NextPage' => min($index + 1, count($pages->pages) - 1),
                        'PrevPage' => max(0, $index - 1),
                        default => $index,
                    };
                    if ($targetIndex !== $index) {
                        return true;
                    }
                }
            }
            $next = $current->get('Next');
            $next = $next instanceof IndirectReference ? $next->target() : $next;
            if ($next instanceof DictionaryType) {
                $pending[] = $next;
            } elseif ($next instanceof ArrayType) {
                foreach ($next->items as $member) {
                    $member = $member instanceof IndirectReference ? $member->target() : $member;
                    if (!$member instanceof DictionaryType) {
                        throw new \InvalidArgumentException('An action Next array contains action dictionaries.');
                    }
                    $pending[] = $member;
                }
            } elseif ($next !== null) {
                throw new \InvalidArgumentException('Action Next is a dictionary or array.');
            }
        }
        return false;
    }
}
