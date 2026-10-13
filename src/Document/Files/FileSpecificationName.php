<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\StringType;

final readonly class FileSpecificationName
{
    public function __construct(public EmbeddedFileKey $key, public StringType $value)
    {
        if ($key === EmbeddedFileKey::UnicodeFile && !$value instanceof TextString) {
            throw new \InvalidArgumentException('UF requires an explicitly encoded PDF text string.');
        }
    }
}
