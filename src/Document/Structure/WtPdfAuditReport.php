<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** A partial audit, deliberately unable to certify WTPDF/PDF-UA conformance. */
final readonly class WtPdfAuditReport
{
    /** @var list<WtPdfFinding> */
    public array $findings;

    public function __construct(public WtPdfConformanceLevel $level, WtPdfFinding ...$findings)
    {
        $this->findings = array_values($findings);
    }

    /** @return list<WtPdfFinding> */
    public function failures(): array
    {
        return array_values(array_filter($this->findings, static fn (WtPdfFinding $finding): bool => $finding->status === WtPdfFindingStatus::Failed));
    }

    /** @return list<WtPdfFinding> */
    public function pending(): array
    {
        return array_values(array_filter($this->findings, static fn (WtPdfFinding $finding): bool => $finding->status !== WtPdfFindingStatus::Failed));
    }

    public function assertNoDetectedFailures(): void
    {
        $failure = $this->failures()[0] ?? null;
        if ($failure !== null) {
            throw new \InvalidArgumentException('WTPDF ' . $failure->clause . ': ' . $failure->message);
        }
    }
}
