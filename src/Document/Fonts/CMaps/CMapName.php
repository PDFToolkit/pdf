<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\NameType;

/** A literal PostScript resource name. PDF's #xx name escapes do not apply inside CMap programs. */
final readonly class CMapName
{
    public function __construct(public NameType $value)
    {
        if ($value->value === '' || preg_match('/[\x00-\x20\x7f-\xff]/', $value->value) === 1 || strpbrk($value->value, '()<>[]{}/%') !== false) {
            throw new \InvalidArgumentException('A CMap resource name must be a nonempty literal PostScript ASCII name.');
        }
    }

    public function program(): string
    {
        return '/' . $this->value->value;
    }
}
