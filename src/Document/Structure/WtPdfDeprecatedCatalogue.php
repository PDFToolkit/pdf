<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\ErrataCollection;
use PDF\Specification\SourceEdition;
use PDF\Specification\SourceReference;

/** Reviewed contexts beyond the introduction's nonexhaustive list. Sources are retained unchanged. */
final readonly class WtPdfDeprecatedCatalogue
{
    /** @var array<string,WtPdfDeprecatedFeature> */
    public array $features;

    public function __construct()
    {
        $edition = new SourceEdition('iso32000-2:2020', 'ISO 32000-2:2020', '2.0', 'iso32000-2', new ErrataCollection(3, '2026-06-01'));
        $graph = WtPdfDeprecatedScope::ConstructedGraph;
        $file = WtPdfDeprecatedScope::SuppliedFileContext;
        $opaque = WtPdfDeprecatedScope::OpaquePayload;
        $review = WtPdfDeprecatedScope::ConsumerOrSemanticReview;
        $rows = [
            ['catalog-needs-rendering', '29', '7.7.2', 117, $graph, 'NeedsRendering is deprecated.'],
            ['forms-xfa', '225', '12.7.2', 545, $graph, 'XFA forms are deprecated; the separate WTPDF prohibition still applies.'],
            ['forms-need-appearances', '225', '12.7.2', 544, $graph, 'NeedAppearances is deprecated.'],
            ['alternate-presentations', '32', '7.7.4', 125, $graph, 'AlternatePresentations are deprecated.'],
            ['resources-procset', '34', '7.8.3', 128, $graph, 'Resource ProcSet declarations are deprecated.'],
            ['filespec-os-names', '43', '7.11.3', 150, $graph, 'DOS, Mac and Unix file-specification names are deprecated.'],
            ['embedded-mac-params', '45', '7.11.4.1', 154, $graph, 'Embedded-file parameter Mac is deprecated.'],
            ['xobject-name', '87', '8.9.5', 275, $graph, 'The image/form XObject Name entry is deprecated.'],
            ['xobject-opi', '87', '8.9.5', 275, $graph, 'XObject OPI proxies are deprecated; image-mask OPI is ignored.'],
            ['font-name', '109', '9.6.2', 328, $graph, 'Simple-font Name entries are deprecated through the Type1 table.'],
            ['font-type1-charset', '120', '9.8.1', 360, $graph, 'Type1 font-descriptor CharSet is deprecated.'],
            ['font-cidset', '122', '9.8.3.1', 363, $graph, 'CID font-descriptor CIDSet is deprecated.'],
            ['cid-collections', '117', '9.7.5.2', 353, $graph, 'Adobe-Japan2 and Adobe-Korea1 character collections are deprecated.'],
            ['extgstate-transfer', '57', '8.4.5', 181, $graph, 'Graphics-state TR and TR2 transfer functions are deprecated; halftone transfer functions are a separate context.'],
            ['blend-mode-array', '57', '8.4.5', 182, $graph, 'Arrays of graphics-state blend modes are deprecated.'],
            ['blend-mode-compatible', '134', '11.3.5', 409, $graph, 'The Compatible blend mode is deprecated and shall not be emitted by writers.'],
            ['viewer-prepress', '147', '12.2', 453, $graph, 'Viewer ViewArea, ViewClip, PrintArea and PrintClip are deprecated.'],
            ['annotations-legacy', '171', '12.5.6', 492, $graph, 'Sound, Movie and TrapNet annotations are deprecated; WTPDF separately forbids them.'],
            ['actions-legacy', '212', '12.6.4.9', 535, $graph, 'Sound and Movie actions are deprecated.'],
            ['launch-os', '207', '12.6.4.6', 532, $graph, 'Platform-specific Launch Win, Mac and Unix entries are deprecated.'],
            ['signature-seed-sha1', '237', '12.7.5.5', 561, $graph, 'SHA1 in a signature seed DigestMethod declaration is deprecated.'],
            ['signature-legacy-subfilter', '255', '12.8.1', 584, $graph, 'adbe.x509.rsa_sha1 and adbe.pkcs7.sha1 are deprecated signature formats.'],
            ['signature-handler-version', '255', '12.8.1', 586, $graph, 'The signature dictionary R handler-version entry is deprecated.'],
            ['signature-ur', 'clause12.8.2.3', '12.8.2.3', 589, $graph, 'UR transforms and usage-rights signatures are deprecated.'],
            ['signature-reference-digest', '256', '12.8.1', 587, $graph, 'MD5 and SHA1 signature-reference digests are deprecated.'],
            ['legal-legacy-counts', '264', '12.8.7', 609, $graph, 'Legal attestation MovieActions, SoundActions and ExternalOPIdicts entries are deprecated.'],
            ['info-metadata', '349', '14.3.3', 731, $file, 'Document Info metadata other than CreationDate and ModDate is deprecated; thread information is not document Info.'],
            ['markinfo-suspects', '353', '14.7.1', 738, $graph, 'The MarkInfo Suspects entry is deprecated.'],
            ['structure-revisions', '355', '14.7.6.3', 754, $graph, 'Structure-element and attribute/class association revision numbers are deprecated.'],
            ['printfield-checked', '383', '14.8.5.6', 806, $graph, 'The lowercase PrintField checked attribute is deprecated; Checked is current.'],
            ['web-capture', 'clause14.10', '14.10', 817, $graph, 'Web Capture structures and their object attributes are deprecated.'],
            ['page-separations', '400', '14.11.4', 834, $graph, 'Page separation dictionaries are deprecated.'],
            ['trapping-appearance', '404', '14.11.6.3', 843, $graph, 'Trap-network appearance mechanisms are deprecated.'],
            ['operator-F', '59', '8.5.3.1', 188, $graph, 'The legacy path-fill operator F is deprecated; use f.'],
            ['encrypted-fdf', '247', '12.7.8.3', 577, $graph, 'EncryptionRevision on an identified embedded FDF stream is deprecated.'],
            ['encryption-version', '20', '7.6.2', 88, $file, 'Encryption V values below 5 are deprecated.'],
            ['standard-revision', '21', '7.6.4.2', 93, $file, 'Standard security revisions below 6 are deprecated; R5 remains prohibited for PDF2 writing.'],
            ['crypt-filter-method', '25', '7.6.6', 106, $file, 'V2 and AESV2 crypt filters are deprecated.'],
            ['crypt-filter-length', '25', '7.6.6', 107, $file, 'Crypt-filter Length is deprecated but remains required by this table; do not remove required entries automatically.'],
            ['assistive-restriction', '22', '7.6.4.2', 95, $file, 'Using permission bit10 to restrict assistive extraction is deprecated.'],
            ['publickey-cms-algorithms', '24', '7.6.5.3', 104, $opaque, 'Inspect public-key CMS envelope algorithms and encrypted recipient permissions with an explicitly selected cryptographic validator.'],
            ['signature-cms-digests', '257', '12.8.3', 593, $opaque, 'Inspect CMS signature and certificate digest algorithms independently; dictionary keys do not reveal all algorithms.'],
            ['raw-content-operators', '59', '8.5.3.1', 188, $opaque, 'Parse opaque content programs independently before excluding deprecated operators.'],
            ['cmap-character-collections', '117', '9.7.5.2', 353, $opaque, 'Inspect collection declarations inside opaque CMap programs with a CMap parser.'],
            ['embedded-fdf-payload', '247', '12.7.8.3', 577, $opaque, 'Inspect untyped embedded FDF payloads with a format reader; attachment names do not prove their format.'],
            ['reader-deprecated-behaviour', 'definition3.15', '3.15', 23, $review, 'A constructed-object audit cannot establish how an external processor ignores deprecated features or applies their contextual exceptions.'],
            ['link-multiple-annotations', 'EC3#133', '14.8.4.7.3', 1010, WtPdfDeprecatedScope::SourceReviewRequired, 'The appended EC3 issue133 diff includes a Link deprecation note. Its approval chain and semantic applicability are not asserted as an implemented native rule.'],
        ];
        $features = [];
        foreach ($rows as [$identifier, $table, $clause, $page, $scope, $description]) {
            $source = new SourceReference($table, $clause, $page, 'ISO 32000-2:2020', '90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230', 'ISO_32000-2_sponsored_EC3.pdf', $edition);
            $related = match ($identifier) {
                'xobject-name', 'xobject-opi' => [['93', '8.10.2', 288]],
                'font-name' => [['110', '9.6.4', 332], ['clause9.6.3', '9.6.3', 331]],
                'viewer-prepress' => [['147', '12.2', 454]],
                'actions-legacy' => [['213', '12.6.4.10', 536]],
                'signature-ur' => [['256', '12.8.1', 587], ['263', '12.8.6', 608]],
                'info-metadata' => [['349', '14.3.3', 732]],
                'assistive-restriction' => [['24', '7.6.5.2', 103]],
                default => [],
            };
            $relatedSources = [];
            foreach ($related as [$relatedTable, $relatedClause, $relatedPage]) {
                $relatedSources[] = new SourceReference($relatedTable, $relatedClause, $relatedPage, $source->standard, $source->documentSha256, $source->document, $edition);
            }
            $features[$identifier] = new WtPdfDeprecatedFeature($identifier, $source, $scope, $description, ...$relatedSources);
        }
        $this->features = $features;
    }

    public function feature(string $identifier): WtPdfDeprecatedFeature
    {
        return $this->features[$identifier] ?? throw new \InvalidArgumentException('Unknown reviewed deprecation: ' . $identifier);
    }
}
