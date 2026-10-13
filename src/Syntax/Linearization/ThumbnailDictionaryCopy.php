<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;

/** Output-only dictionary with actual copied reference targets; lazy entries support indirect cycles. */
final class ThumbnailDictionaryCopy extends DictionaryType
{
    /** @var array<string, Entry> */
    private array $replacements = [];
    /** @var array<string, true> */
    private array $removed = [];
    public function __construct(private readonly DictionaryType $source, private readonly ThumbnailGraphCopies $copies)
    {
        parent::__construct(...iterator_to_array($source->entries(), false));
    }

    public function entries(): iterable
    {
        $remaining = $this->replacements;
        foreach ($this->source->entries() as $entry) {
            $key = $entry->key->value;
            if (!isset($this->removed[$key])) {
                yield $remaining[$key] ?? new Entry($entry->key, $this->copies->value($entry->value));
            }
            unset($remaining[$key]);
        }
        yield from $remaining;
    }

    public function with(Entry $entry): static
    {
        $copy = clone $this;
        $copy->replacements[$entry->key->value] = $entry;
        unset($copy->removed[$entry->key->value]);
        return $copy;
    }

    public function without(\PDF\Syntax\Object\NameType|string $key): static
    {
        $key = $key instanceof \PDF\Syntax\Object\NameType ? $key->value : $key;
        $copy = clone $this;
        $copy->removed[$key] = true;
        unset($copy->replacements[$key]);
        return $copy;
    }
}
