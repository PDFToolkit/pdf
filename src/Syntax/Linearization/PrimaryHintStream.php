<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Table F.2: page offsets at zero, followed by shared and named hint tables. */
final class PrimaryHintStream extends StreamObject
{
    public function __construct(PageOffsetHintTable $pages, SharedObjectHintTable $shared, HintTableEntry ...$other)
    {
        $sharedBits = (new HintBitWidth(new IntegerType(count($shared->groups) - 1)))->bits;
        $bytes = $pages->bytes($sharedBits);
        $entries = [new Entry(new NameType('S'), new IntegerType(strlen($bytes)))];
        $bytes .= $shared->bytes();
        $seen = ['S' => true];
        foreach ($other as $entry) {
            if (isset($seen[$entry->name->value])) {
                throw new \InvalidArgumentException('A primary hint stream contains one table per name.');
            }
            $seen[$entry->name->value] = true;
            $entries[] = new Entry($entry->name, new IntegerType(strlen($bytes)));
            $bytes .= $entry->table instanceof EmbeddedFileHintTable ? $entry->table->bytes($sharedBits) : $entry->table->bytes();
        }
        parent::__construct($bytes, new DictionaryType(...$entries));
    }
}
