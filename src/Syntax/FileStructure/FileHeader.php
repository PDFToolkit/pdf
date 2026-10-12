<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

final readonly class FileHeader implements \Stringable
{
    public PdfVersion $version;
    public function __construct(PdfVersion|string $version = PdfVersion::PDF17)
    {
        $this->version = is_string($version) ? PdfVersion::from($version) : $version;
    }
    public function __toString(): string
    {
        return '%PDF-' . $this->version->value . "\n%\xe2\xe3\xcf\xd3\n";
    }
}
