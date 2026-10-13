<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

enum SignatureLockAction: string
{
    case All = 'All';
    case Include = 'Include';
    case Exclude = 'Exclude';
}
