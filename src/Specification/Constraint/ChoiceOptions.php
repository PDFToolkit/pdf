<?php

declare(strict_types=1);

namespace PDF\Specification\Constraint;

use PDF\Specification\DictionaryConstraint;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringType;

final readonly class ChoiceOptions implements DictionaryConstraint
{
    public function __construct(public bool $pdf20Ec3 = false)
    {
    }

    public function validate(DictionaryType $dictionary): void
    {
        $values = new DictionaryValues();
        $options = $values->value($dictionary, 'Opt');
        $exports = [];
        $ambiguous = false;
        $decoder = new TextStringDecoder($this->pdf20Ec3 ? PdfVersion::PDF20 : PdfVersion::PDF17);
        if ($options instanceof ArrayType) {
            foreach ($options->items as $option) {
                $option = $option instanceof IndirectReference ? $option->target() : $option;
                if ($option instanceof StringType) {
                    $key = base64_encode($decoder->decode($option));
                    $display = $decoder->decode($option);
                    $ambiguous = $ambiguous || (isset($exports[$key]) && $exports[$key] !== $display);
                    $exports[$key] = $display;
                    continue;
                }
                if (!$option instanceof ArrayType || count($option) !== 2) {
                    throw new \InvalidArgumentException('A choice option requires a string or two-string export/display pair.');
                }
                $texts = [];
                foreach ($option->items as $text) {
                    $text = $text instanceof IndirectReference ? $text->target() : $text;
                    if (!$text instanceof StringType) {
                        throw new \InvalidArgumentException('Choice export/display values require text strings.');
                    }
                    $texts[] = $decoder->decode($text);
                }
                $key = base64_encode($texts[0]);
                $ambiguous = $ambiguous || (isset($exports[$key]) && $exports[$key] !== $texts[1]);
                $exports[$key] = $texts[1];
            }
        }
        $indices = $values->numbers($dictionary, 'I');
        if ($this->pdf20Ec3 && $ambiguous && $indices === null) {
            throw new \InvalidArgumentException('PDF 2.0 duplicate choice export values with distinct display names require /I.');
        }
        if ($indices !== null) {
            (new NumberArray('I', minimum: 0, integer: true, ascending: true))->validate($dictionary);
            foreach ($indices as $index) {
                if (!$options instanceof ArrayType || $index >= count($options)) {
                    throw new \InvalidArgumentException('A choice selection index must identify an /Opt item.');
                }
            }
        }
        $top = $values->value($dictionary, 'TI');
        if ($top instanceof IntegerType && $top->integer() !== 0 && (!$options instanceof ArrayType || $top->integer() >= count($options))) {
            throw new \InvalidArgumentException('/TI must identify an /Opt item.');
        }
    }
}
