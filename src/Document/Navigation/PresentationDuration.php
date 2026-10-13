<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\NumericType;

/** A nonnegative time interval in seconds; zero means immediate, absence means no timer. */
final class PresentationDuration extends NumericType implements VersionedComponent
{
    public function __construct(NumericType $seconds)
    {
        if ($seconds->value < 0) {
            throw new \InvalidArgumentException('A presentation time interval cannot be negative.');
        }
        parent::__construct($seconds->value);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF11, 'Presentation timing');
    }
}
