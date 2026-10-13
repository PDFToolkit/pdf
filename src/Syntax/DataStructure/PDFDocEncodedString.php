<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

/** 7.9.2.5: explicitly supplied, defined PDFDocEncoding bytes. */
final class PDFDocEncodedString extends ByteString
{
    public readonly string $text;

    public function __construct(string $bytes)
    {
        $this->text = (new PDFDocEncoding())->decode($bytes);
        parent::__construct($bytes);
    }
}
