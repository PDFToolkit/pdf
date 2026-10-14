<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** An auditable result always identifies the supplied normative source and physical page. */
final readonly class WtPdfFinding
{
    public const string DOCUMENT = 'Well-Tagged-PDF-WTPDF-1.0.pdf';

    public function __construct(public string $clause, public int $pdfPage, public WtPdfFindingStatus $status, public string $message, public ?StructureElement $element = null)
    {
        if ($pdfPage < 1 || $pdfPage > 57 || $clause === '' || $message === '') {
            throw new \InvalidArgumentException('A WTPDF finding requires a clause, physical source page and explanation.');
        }
    }
}
