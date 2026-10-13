<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

enum SignatureTransformMethod: string
{
    case DocMDP = 'DocMDP';
    case FieldMDP = 'FieldMDP';
    case UR = 'UR';
}
