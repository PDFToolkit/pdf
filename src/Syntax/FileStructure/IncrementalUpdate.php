<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\IncrementalWriter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** ISO 32000-1, 7.5.6: explicitly staged objects appended to a generated revision. */
final readonly class IncrementalUpdate implements \Stringable
{
    public function __construct(
        public FileRevision $previous,
        public FileBody $body,
        public ?IndirectReference $root = null,
        public ?DictionaryType $trailerEntries = null,
    ) {
        foreach (['Size', 'Root', 'Prev', 'XRefStm', 'Encrypt'] as $managed) {
            if ($trailerEntries?->has($managed)) {
                throw new \InvalidArgumentException('Managed or unsupported incremental trailer entry: ' . $managed);
            }
        }
    }

    public function toRevision(): FileRevision
    {
        return (new IncrementalWriter())->serialize($this);
    }

    public function toPdf(): string
    {
        return $this->toRevision()->bytes;
    }

    public function writeTo(string $path): void
    {
        $output = new \PDF\Serialization\PdfFileOutput();
        $output->validatePath($path);
        $output->write($path, $this->toPdf());
    }

    public function __toString(): string
    {
        return $this->toPdf();
    }
}
