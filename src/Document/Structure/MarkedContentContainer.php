<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StreamObject;

/** Explicit page/auxiliary stream and its StructParents key (table 326, PDF page 573). */
final readonly class MarkedContentContainer
{
    public function __construct(
        public PageObject $page,
        public IntegerType $parentTreeKey,
        public ?StreamObject $stream = null,
        public ?BasicType $streamOwner = null,
    ) {
        if ($parentTreeKey->integer() < 0 || ($streamOwner !== null && $stream === null)) {
            throw new \InvalidArgumentException('A content container needs a non-negative key and an owner only for an auxiliary stream.');
        }
        if ($streamOwner instanceof IndirectReference) {
            throw new \InvalidArgumentException('Supply the owner object itself; its reference is derived.');
        }
    }

    public function object(): PageObject|StreamObject
    {
        return $this->stream ?? $this->page;
    }

    public function dictionary(): DictionaryType
    {
        return $this->stream?->dictionary() ?? $this->page;
    }

    public function validate(): void
    {
        $dictionary = $this->dictionary();
        $key = $dictionary->get('StructParents');
        if (!$key instanceof IntegerType || $key->integer() !== $this->parentTreeKey->integer() || $dictionary->has('StructParent')) {
            throw new \InvalidArgumentException('A content container must carry exactly its explicit StructParents key, without StructParent.');
        }
    }

    /** @return list<StreamObject> */
    public function streams(): array
    {
        if ($this->stream !== null) {
            return [$this->stream];
        }
        $contents = $this->page->get('Contents');
        $contents = $contents instanceof IndirectReference ? $contents->target() : $contents;
        if ($contents instanceof StreamObject) {
            return [$contents];
        }
        $streams = [];
        if ($contents instanceof ArrayType) {
            foreach ($contents->items as $reference) {
                if (!$reference instanceof IndirectReference || !$reference->target() instanceof StreamObject) {
                    throw new \InvalidArgumentException('Page contents must reference streams.');
                }
                $streams[] = $reference->target();
            }
        }
        return $streams;
    }
}
