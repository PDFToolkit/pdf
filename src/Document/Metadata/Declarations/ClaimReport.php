<?php

declare(strict_types=1);

namespace PDF\Document\Metadata\Declarations;

use PDF\Document\Metadata\MetadataUri;

/** PDF-Declarations.pdf p.10: an absolute report URL or embedded-file fragment. Never fetched. */
final readonly class ClaimReport
{
    public function __construct(public string $value)
    {
        if (str_starts_with($value, '#')) {
            new MetadataUri('urn:pdf-report:' . $value);
            if (preg_match('/^#ef=[^\x00-\x20\x7F]+$/D', $value) !== 1) {
                throw new \InvalidArgumentException('An embedded report uses an ef fragment with an encoded file name.');
            }
        } else {
            new MetadataUri($value);
        }
    }
}
