<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\NameType;

/** ISO 32000-2 EC3 #155, physical p.153: MIME type/subtype without parameters or NUMBER SIGN. */
final readonly class EmbeddedFileMediaType
{
    public function __construct(public NameType $name)
    {
        if (preg_match("@^[!$%&'*+.^_`|~0-9A-Za-z-]+/[!$%&'*+.^_`|~0-9A-Za-z-]+$@D", $name->value) !== 1) {
            throw new \InvalidArgumentException('An embedded-file MIME Subtype requires type/subtype without parameters, semicolon, equals or number sign.');
        }
    }
}
