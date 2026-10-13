<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Catalogue article ordering; equal titles are permitted and retain explicit order. */
final class ArticleThreads extends ArrayType
{
    public function __construct(ArticleThread ...$threads)
    {
        $seen = new \SplObjectStorage();
        foreach ($threads as $thread) {
            if ($seen->offsetExists($thread)) {
                throw new \InvalidArgumentException('The same thread cannot be repeated in the explicit catalogue article list.');
            }
            $seen->offsetSet($thread);
        }
        parent::__construct(...array_map(static fn (ArticleThread $thread): IndirectReference => new IndirectReference($thread), $threads));
    }

    public function catalogEntry(): Entry
    {
        return new Entry(new NameType('Threads'), new IndirectReference($this));
    }
}
