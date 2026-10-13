<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Header and optional catalog version are distinct declarations (7.5.2, Table 28). */
final readonly class EffectivePdfVersion
{
    public PdfVersion $value;

    public function __construct(public FileHeader $header, DictionaryType $catalog)
    {
        $version = $catalog->get('Version');
        if ($version !== null && (!$version instanceof NameType || PdfVersion::tryFrom($version->value) === null)) {
            throw new \InvalidArgumentException('Catalog Version is an explicit PDF 1.0–1.7 name.');
        }
        $this->value = $version instanceof NameType && version_compare($version->value, $header->version->value, '>')
            ? PdfVersion::from($version->value) : $header->version;
    }

    public function require(PdfVersion $minimum, string $feature): void
    {
        if (version_compare($this->value->value, $minimum->value, '<')) {
            throw new \LogicException($feature . ' requires PDF ' . $minimum->value . ' or later.');
        }
    }
}
