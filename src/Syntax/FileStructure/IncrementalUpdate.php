<?php

declare(strict_types=1);

namespace PDF\Syntax\FileStructure;

use PDF\Serialization\IncrementalWriter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** ISO 32000-1, 7.5.6: explicitly staged objects appended to a generated revision. */
final readonly class IncrementalUpdate implements \Stringable
{
    /** @var list<ReleaseIndirectObject|ReuseObjectNumber> */
    public array $objectOperations;
    public ?\PDF\Document\Signatures\UsageRightsAuthorization $usageRights;
    public function __construct(
        public FileRevision $previous,
        public FileBody $body,
        public ?IndirectReference $root = null,
        public ?DictionaryType $trailerEntries = null,
        ReleaseIndirectObject|ReuseObjectNumber|\PDF\Document\Signatures\UsageRightsAuthorization ...$objectOperations,
    ) {
        foreach (['Size', 'Root', 'Prev', 'XRefStm', 'Encrypt'] as $managed) {
            if ($trailerEntries?->has($managed)) {
                throw new \InvalidArgumentException('Managed or unsupported incremental trailer entry: ' . $managed);
            }
        }
        $operations = [];
        $usageRights = null;
        foreach ($objectOperations as $operation) {
            if ($operation instanceof \PDF\Document\Signatures\UsageRightsAuthorization) {
                if ($usageRights !== null) {
                    throw new \InvalidArgumentException('An incremental update selects one usage-rights authorization.');
                }
                $usageRights = $operation;
            } else {
                $operations[] = $operation;
            }
        }
        $this->objectOperations = $operations;
        $this->usageRights = $usageRights;
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
