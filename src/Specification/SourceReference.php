<?php

declare(strict_types=1);

namespace PDF\Specification;

final readonly class SourceReference
{
    public function __construct(
        public string $table,
        public ?string $clause,
        public int $pdfPage,
        public string $standard = 'ISO 32000-1:2008',
        public string $documentSha256 = '2dc784890e65da6c324d46ece182fb4705b751e49587b1bbe9c3225c25fed4d3',
        public string $document = 'PDF32000_2008.pdf',
    ) {
    }
}
