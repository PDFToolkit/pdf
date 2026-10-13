<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

enum UsageRightsMode: string
{
    case Legacy = 'UR';
    case ByteRange = 'UR3';
}
