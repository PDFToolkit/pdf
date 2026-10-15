<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\FileStructure\BasicType;

final readonly class WtPdfDeprecatedNode
{
    public function __construct(public BasicType $value, public WtPdfDeprecatedDomain $domain, public string $path, public int $depth = 0, public ?string $fieldType = null)
    {
    }
}
