<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\Object\NameType;

/** /AF /resource BDC (ISO_32000-2_sponsored_EC3.pdf, 14.13.5/EC3 issue374, PDF p.1015). */
final readonly class BeginAssociatedFilesMarkedContent extends Operator
{
    public const string SYMBOL = 'BDC';

    public function __construct(public NameType $properties)
    {
        parent::__construct(new NameType('AF'), $properties);
    }
}
