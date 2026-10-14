<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** Inspects a constructed PDF graph. It never adds declarations or certifies accessibility. */
final readonly class WtPdfAudit
{
    public function __construct(private WtPdfStructureChecks $structure = new WtPdfStructureChecks(), private WtPdfGraphChecks $graph = new WtPdfGraphChecks(), private WtPdfFontUsageChecks $fonts = new WtPdfFontUsageChecks(), private WtPdfNavigationChecks $navigation = new WtPdfNavigationChecks())
    {
    }

    public function inspect(DictionaryType $catalog, EffectivePdfVersion $version, WtPdfConformanceLevel $level): WtPdfAuditReport
    {
        $findings = [];
        if ($version->value !== PdfVersion::PDF20) {
            $findings[] = new WtPdfFinding('6.2', 10, WtPdfFindingStatus::Failed, 'WTPDF requires a PDF 2.0 document.');
        }
        $root = $catalog->get('StructTreeRoot');
        $root = $root instanceof IndirectReference ? $root->target() : $root;
        if (!$root instanceof StructureTreeRoot) {
            $findings[] = new WtPdfFinding('8.2', 14, WtPdfFindingStatus::AdditionalValidationRequired, 'A managed StructureTreeRoot is required to audit native role, containment and content associations.');
        } else {
            try {
                $root->validateCatalog($catalog);
                array_push($findings, ...$this->structure->inspect($root, $level));
            } catch (\InvalidArgumentException|\LogicException $error) {
                $findings[] = new WtPdfFinding('8.2', 14, WtPdfFindingStatus::Failed, $error->getMessage());
            }
        }
        try {
            array_push($findings, ...$this->graph->inspect($catalog, $root instanceof StructureTreeRoot ? $root : null, $level));
        } catch (\InvalidArgumentException|\LogicException $error) {
            $findings[] = new WtPdfFinding('6.2', 10, WtPdfFindingStatus::Failed, 'Document graph: ' . $error->getMessage());
        }
        try {
            array_push($findings, ...$this->fonts->inspect($catalog, $level));
        } catch (\InvalidArgumentException|\LogicException $error) {
            $findings[] = new WtPdfFinding('8.4.5', 32, WtPdfFindingStatus::Failed, 'Font usage: ' . $error->getMessage());
        }
        try {
            array_push($findings, ...$this->navigation->inspect($catalog));
        } catch (\InvalidArgumentException|\LogicException $error) {
            $findings[] = new WtPdfFinding('8.8', 36, WtPdfFindingStatus::Failed, 'Navigation: ' . $error->getMessage());
        }
        // These judgments cannot be inferred from syntactic correctness or an XMP assertion.
        foreach ([
            ['6.2', 10, 'Verify full ISO 32000-2 compliance and accessibility of any necessary embedded content.'],
            ['8.2.2–8.2.6', 14, 'Review real-content/artifact decisions, semantic types, logical reading order, labels, captions, headings, reference intent and semantically significant attributes.'],
            ['8.4.2–8.5.2', 31, 'Review language, text alternatives, Unicode private-use necessity and faithful textual semantics.'],
            ['8.9–8.10', 37, 'Review annotation placement, rich-text/plain-text equivalence, context, widget labels, captions and action descriptions.'],
            ['8.12–8.14', 48, 'Review perceived page numbers, navigation, script interaction, URI image-map alternatives and descriptions of embedded content.'],
        ] as [$clause, $page, $message]) {
            $findings[] = new WtPdfFinding($clause, $page, WtPdfFindingStatus::HumanReviewRequired, $message);
        }
        return new WtPdfAuditReport($level, ...$findings);
    }
}
