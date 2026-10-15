<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\SourceReference;

/** One reviewed source feature; a catalogue row alone is not evidence that a document was checked. */
final readonly class WtPdfDeprecatedFeature
{
    /** @var list<SourceReference> */
    public array $sources;

    public function __construct(public string $identifier, public SourceReference $source, public WtPdfDeprecatedScope $scope, public string $description, SourceReference ...$relatedSources)
    {
        $this->sources = [$source, ...array_values($relatedSources)];
    }

    public function recommendation(string $path): WtPdfFinding
    {
        $references = array_map(static fn (SourceReference $source): string => $source->clause . ' p' . $source->pdfPage, $this->sources);
        return new WtPdfFinding('6.2', 10, WtPdfFindingStatus::Recommendation, $this->identifier . ': ' . $this->description . ' [' . $path . '; ISO 32000-2 ' . implode('; ', $references) . '].');
    }
}
