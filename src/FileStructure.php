<?php

declare(strict_types=1);

namespace PDF;

use PDF\Serialization\FileWriter;
use PDF\Serialization\PdfFileOutput;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** The file's header, body, root and trailer additions (7.5). */
final readonly class FileStructure implements \Stringable
{
    public function __construct(
        public FileHeader $header,
        public FileBody $body,
        public ?IndirectReference $root = null,
        public ?DictionaryType $trailerEntries = null,
        public CrossReferenceFormat $crossReferenceFormat = CrossReferenceFormat::Table,
    ) {
    }

    public function toPdf(): string
    {
        return (new FileWriter())->serialize($this);
    }

    public function toRevision(): FileRevision
    {
        return (new FileWriter())->revision($this);
    }

    /** Writes to a filesystem path chosen by the application; stream URIs are refused. */
    public function writeTo(string $path): void
    {
        $output = new PdfFileOutput();
        $output->validatePath($path);
        $output->write($path, $this->toPdf());
    }

    public function __toString(): string
    {
        return $this->toPdf();
    }
}
