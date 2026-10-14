<?php

declare(strict_types=1);

namespace PDF\Specification;

final readonly class SourceReference
{
    public SourceEdition $edition;

    public function __construct(
        public string $table,
        public ?string $clause,
        public int $pdfPage,
        public string $standard = 'ISO 32000-1:2008',
        public string $documentSha256 = '2dc784890e65da6c324d46ece182fb4705b751e49587b1bbe9c3225c25fed4d3',
        public string $document = 'PDF32000_2008.pdf',
        ?SourceEdition $edition = null,
    ) {
        if ($edition !== null && $edition->standard !== $standard) {
            throw new \InvalidArgumentException('The source reference and its explicit edition must identify the same standard.');
        }
        // Existing six-argument references retain their source fields and are
        // assigned to the historical corpus. New editions are explicit objects.
        $this->edition = $edition ?? match (true) {
            $standard === 'ISO 32000-1:2008' => new SourceEdition('iso32000-1:2008', $standard, '1.7', 'iso32000-1'),
            str_starts_with($standard, 'Adobe Supplement to ISO 32000, BaseVersion 1.7, ExtensionLevel 3') => new SourceEdition('adobe-pdf17-extension-level-3:2008', $standard, '1.7', 'iso32000-1'),
            default => new SourceEdition('legacy:' . $standard, $standard, '1.7', 'legacy:' . $standard),
        };
    }
}
