<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Table44/page111: coupled EF/RF filename keys and actual effective stream targets. */
final readonly class FileSpecificationRules
{
    public function validate(DictionaryType $specification, ?DocumentGraphContext $graph = null): void
    {
        $embedded = $this->entry($specification, 'EF', $graph);
        $related = $this->entry($specification, 'RF', $graph);
        $type = $this->entry($specification, 'Type', $graph);
        $type = $type === null ? null : $this->resolve($type, $graph);
        if (($type !== null && (!$type instanceof NameType || $type->value !== 'Filespec')) || (($embedded !== null || $related !== null) && $type === null)) {
            throw new \InvalidArgumentException('An EF/RF file specification requires Type Filespec.');
        }
        $hasFileName = false;
        foreach (EmbeddedFileKey::cases() as $key) {
            $name = $this->entry($specification, $key->value, $graph);
            if ($name !== null) {
                if (!$this->resolve($name, $graph) instanceof StringType) {
                    throw new \InvalidArgumentException('File-specification filenames must be strings.');
                }
                $hasFileName = $hasFileName || $key !== EmbeddedFileKey::UnicodeFile;
            }
        }
        if (!$hasFileName) {
            throw new \InvalidArgumentException('F is required when DOS, Mac and Unix are absent.');
        }
        if ($related !== null && $embedded === null) {
            throw new \InvalidArgumentException('RF requires a corresponding EF dictionary.');
        }
        $ef = $embedded === null ? null : $this->resolve($embedded, $graph);
        foreach (['EF' => $embedded, 'RF' => $related] as $kind => $value) {
            if ($value === null) {
                continue;
            }
            $dictionary = $this->resolve($value, $graph);
            if (!$dictionary instanceof DictionaryType) {
                throw new \InvalidArgumentException($kind . ' must resolve to a dictionary.');
            }
            foreach ($dictionary->entries() as $entry) {
                if ($this->resolve($entry->value, $graph) instanceof NullType) {
                    continue;
                }
                if (EmbeddedFileKey::tryFrom($entry->key->value) === null || $this->entry($specification, $entry->key->value, $graph) === null) {
                    throw new \InvalidArgumentException('Every EF/RF key must have a corresponding filename in the file specification.');
                }
                if ($kind === 'EF') {
                    $this->embeddedStream($entry->value, $graph);
                    continue;
                }
                if (!$ef instanceof DictionaryType || $this->entry($ef, $entry->key->value, $graph) === null) {
                    throw new \InvalidArgumentException('Every RF key must also be present in EF.');
                }
                $pairs = $this->resolve($entry->value, $graph);
                if (!$pairs instanceof ArrayType || count($pairs) % 2 !== 0) {
                    throw new \InvalidArgumentException('Related files require an array of filename/embedded-stream pairs.');
                }
                for ($i = 0; $i < count($pairs); $i += 2) {
                    if (!$this->resolve($pairs->items[$i], $graph) instanceof StringType) {
                        throw new \InvalidArgumentException('Every related-file name must be a string.');
                    }
                    $this->embeddedStream($pairs->items[$i + 1], $graph);
                }
            }
        }
    }

    private function embeddedStream(BasicType $value, ?DocumentGraphContext $graph): void
    {
        $stream = $value instanceof IndirectReference ? $this->resolve($value, $graph) : null;
        $type = $stream instanceof StreamObject ? $this->entry($stream->dictionary(), 'Type', $graph) : null;
        if (!$stream instanceof StreamObject || ($type !== null && (!$type instanceof NameType || $type->value !== 'EmbeddedFile'))) {
            throw new \InvalidArgumentException('Embedded-file values require indirect embedded-file streams.');
        }
    }

    private function entry(DictionaryType $dictionary, string $key, ?DocumentGraphContext $graph): ?BasicType
    {
        $value = $dictionary->get($key);
        return $value === null || $this->resolve($value, $graph) instanceof NullType ? null : $value;
    }

    private function resolve(BasicType $value, ?DocumentGraphContext $graph): BasicType
    {
        return $graph === null ? ($value instanceof IndirectReference ? $value->target() : $value) : $graph->resolve($value);
    }
}
