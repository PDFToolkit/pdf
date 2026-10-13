<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Compact integer K form; requires the containing element's own Pg (PDF page 569). */
final readonly class PageMarkedContent
{
    public function __construct(public MarkedContentContainer $container, public MarkedContentIdentifier $identifier)
    {
        if ($container->stream !== null) {
            throw new \InvalidArgumentException('An auxiliary stream requires an MCR dictionary instead of a bare MCID.');
        }
    }
}
