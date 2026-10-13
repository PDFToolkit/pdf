<?php

declare(strict_types=1);

namespace PDF\Syntax\Linearization;

use PDF\Syntax\Object\StreamObject;

/** Seamless continuation of decoded primary hint bytes, Annex F.3.6 (PDF page 690). */
final class OverflowHintStream extends StreamObject
{
    public function __construct(string $bytes)
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('An overflow hint stream must contain continuation data.');
        }
        // Table offsets belong only to the primary dictionary, including tables
        // that start in this continuation. Length is managed by StreamObject.
        parent::__construct($bytes);
    }
}
