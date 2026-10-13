<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\StringObject\LiteralString;

/** Component separators and relative resolution, without filesystem or character conversion. */
final class FileSpecificationString extends LiteralString implements VersionedComponent
{
    /** @var list<FileNameComponent> */
    public readonly array $components;

    public function __construct(public readonly FilePathKind $kind, FileNameComponent ...$components)
    {
        if ($kind === FilePathKind::Relative && count($components) > 1 && $components[0]->bytes->value === '') {
            throw new \InvalidArgumentException('A leading empty component makes the written file specification absolute.');
        }
        $this->components = array_values($components);
        parent::__construct(($kind === FilePathKind::Absolute ? '/' : '') . implode('/', array_map(static fn (FileNameComponent $component): string => $component->escapedBytes(), $components)));
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF11, 'File specifications');
    }

    /** Non-URL resolution in7.11.2.2, PDF pages108–109; the containing filename is removed. */
    public function resolvedAgainst(self $containingFile): self
    {
        if ($this->kind === FilePathKind::Absolute) {
            return $this;
        }
        if ($containingFile->kind !== FilePathKind::Absolute) {
            throw new \InvalidArgumentException('An absolute containing-file specification is required for absolute resolution.');
        }
        $base = $containingFile->components;
        array_pop($base);
        $result = [];
        foreach ([...$base, ...$this->components] as $component) {
            if ($component->bytes->value === '..' && $result !== [] && $result[array_key_last($result)]->bytes->value !== '..') {
                array_pop($result);
            } else {
                $result[] = $component;
            }
        }
        return new self(FilePathKind::Absolute, ...$result);
    }
}
