<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;

/** ISO7.4.4.4, physical PDF page37: every row has its own PNG selector byte. */
final readonly class PngPredictor implements Predictor
{
    public IntegerType $predictor;
    public function __construct(public PredictorLayout $layout = new PredictorLayout(), public PngFilterSelection $selection = new AdaptivePngFilterSelection())
    {
        $this->predictor = $selection->predictor();
        if ($this->predictor->integer() < 10 || $this->predictor->integer() > 15) {
            throw new \InvalidArgumentException('A PNG predictor selection declares values10–15.');
        }
    }

    public function parameters(): DictionaryType
    {
        return $this->layout->parameters($this->predictor);
    }

    public function encode(string $bytes): string
    {
        $this->layout->validateBytes($bytes);
        if ($bytes === '') {
            return '';
        }
        $output = '';
        $previous = str_repeat("\0", $this->layout->bytesPerRow);
        for ($start = 0; $start < strlen($bytes); $start += $this->layout->bytesPerRow) {
            $row = substr($bytes, $start, $this->layout->bytesPerRow);
            $filter = $this->selection->select($row, $previous, $this->layout);
            if ($this->predictor->integer() < 15 && $filter->value !== $this->predictor->integer() - 10) {
                throw new \LogicException('A fixed PNG prediction policy must emit its declared filter on every row.');
            }
            $output .= chr($filter->value) . $filter->encode($row, $previous, $this->layout);
            $previous = $row;
        }
        return $output;
    }
}
