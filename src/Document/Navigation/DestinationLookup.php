<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Resolve a local name from its correct catalogue namespace; never load another file. */
final readonly class DestinationLookup
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function find(NameType|StringType $name, DictionaryType $catalog): ArrayType
    {
        if ($name instanceof NameType) {
            $dictionary = $catalog->get('Dests');
            $dictionary = $dictionary instanceof IndirectReference ? $dictionary->target() : $dictionary;
            if (!$dictionary instanceof DictionaryType) {
                throw new \InvalidArgumentException('A name destination requires catalogue Dests.');
            }
            $value = $dictionary->get($name);
        } else {
            $names = $catalog->get('Names');
            $names = $names instanceof IndirectReference ? $names->target() : $names;
            $tree = $names instanceof DictionaryType ? $names->get('Dests') : null;
            $tree = $tree instanceof IndirectReference ? $tree->target() : $tree;
            if (!$tree instanceof DictionaryType) {
                throw new \InvalidArgumentException('A string destination requires catalogue Names.Dests.');
            }
            $value = null;
            $pending = [[$tree, 0]];
            $seen = new \SplObjectStorage();
            $keys = [];
            while ($pending !== []) {
                [$node, $depth] = array_pop($pending);
                if ($depth > $this->limits->maxDepth || count($seen) >= $this->limits->maxNodes || $seen->offsetExists($node)) {
                    throw new \InvalidArgumentException('The destination name tree repeats an identity or exceeds its budget.');
                }
                $seen->offsetSet($node);
                $pairs = $node->get('Names');
                $pairs = $pairs instanceof IndirectReference ? $pairs->target() : $pairs;
                $kids = $node->get('Kids');
                $kids = $kids instanceof IndirectReference ? $kids->target() : $kids;
                if ($pairs instanceof ArrayType && $kids === null) {
                    if (count($pairs) % 2 !== 0) {
                        throw new \InvalidArgumentException('Destination name-tree leaves contain key/value pairs.');
                    }
                    for ($i = 0; $i < count($pairs); $i += 2) {
                        $key = $pairs->items[$i];
                        if (!$key instanceof StringType || isset($keys[bin2hex($key->value)])) {
                            throw new \InvalidArgumentException('Destination name-tree keys are unique byte strings.');
                        }
                        $keys[bin2hex($key->value)] = true;
                        if ($key->value === $name->value) {
                            $value = $pairs->items[$i + 1];
                        }
                    }
                } elseif ($kids instanceof ArrayType && $pairs === null && count($kids) > 0) {
                    foreach ($kids->items as $kid) {
                        if (!$kid instanceof IndirectReference || !$kid->target() instanceof DictionaryType) {
                            throw new \InvalidArgumentException('Destination name-tree Kids must be indirect dictionaries.');
                        }
                        $pending[] = [$kid->target(), $depth + 1];
                    }
                } else {
                    throw new \InvalidArgumentException('A destination name tree is a leaf or a nonempty branch.');
                }
            }
        }
        $value = $value instanceof IndirectReference ? $value->target() : $value;
        if ($value instanceof DictionaryType) {
            $value = $value->get('D');
            $value = $value instanceof IndirectReference ? $value->target() : $value;
        }
        if (!$value instanceof ArrayType) {
            throw new \InvalidArgumentException('The named destination has no explicit destination array.');
        }
        $parameters = new ExplicitDestinationParameters($value);
        (new CatalogPages($catalog, $this->limits))->index($parameters->page());
        return $value;
    }
}
