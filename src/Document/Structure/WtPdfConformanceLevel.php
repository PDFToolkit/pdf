<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Well-Tagged-PDF-WTPDF-1.0.pdf, 6.1.2–6.1.3, physical pages 9–10. */
enum WtPdfConformanceLevel
{
    case Reuse;
    case Accessibility;

    public function declarationIdentifier(): string
    {
        return match ($this) {
            self::Reuse => 'http://pdfa.org/declarations/wtpdf/#reuse1.0',
            self::Accessibility => 'http://pdfa.org/declarations/wtpdf/#accessibility1.0',
        };
    }
}
