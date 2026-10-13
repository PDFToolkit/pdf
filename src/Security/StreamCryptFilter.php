<?php

declare(strict_types=1);

namespace PDF\Security;

use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Resolve an explicit first Crypt decoding filter (ISO 7.4.10, PDF page 54). */
final readonly class StreamCryptFilter
{
    public function explicit(StreamObject $stream): ?NameType
    {
        $name = null;
        foreach ($stream->filters as $index => $filter) {
            if ($filter->name()->value !== 'Crypt') {
                continue;
            }
            if ($index !== 0 || $name !== null) {
                throw new \LogicException('Crypt must be the unique first stream decoding filter.');
            }
            $selected = $filter->parameters()?->get('Name');
            if ($selected !== null && !$selected instanceof NameType) {
                throw new \InvalidArgumentException('A Crypt filter name must be a direct PDF name.');
            }
            $name = $selected ?? new NameType('Identity');
        }
        return $name;
    }
}
