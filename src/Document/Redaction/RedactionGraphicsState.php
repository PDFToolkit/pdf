<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\Object\DictionaryType;

/** Mutable interpreter state is owned by one explicit content traversal. */
final class RedactionGraphicsState
{
    public Matrix $matrix;
    public float $lineWidth = 1;
    public float $miterLimit = 10;
    public ?DictionaryType $font = null;
    public float $fontSize = 0;
    public float $characterSpacing = 0;
    public float $wordSpacing = 0;
    public float $horizontalScaling = 1;
    public float $leading = 0;
    public float $rise = 0;
    public int $textMode = 0;
    public Matrix $textMatrix;
    public Matrix $lineMatrix;

    public function __construct(Matrix $matrix)
    {
        $this->matrix = $matrix;
        $this->textMatrix = $this->lineMatrix = new Matrix();
    }
}
