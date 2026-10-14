<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\StringType;

/** A structure ID in a remote/embedded PDF; never a local indirect reference. */
final class RemoteStructureDestination extends ArrayType implements VersionedComponent
{
    public function __construct(public readonly StringType $identifier, public readonly DestinationView $view)
    {
        parent::__construct($identifier, $view->fit, ...$view->coordinates);
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF20, 'Remote structure destinations');
    }
}
