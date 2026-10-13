<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Syntax\Object\DictionaryType;

interface SignatureTransform
{
    public function method(): SignatureTransformMethod;
    public function parameters(): DictionaryType;
}
