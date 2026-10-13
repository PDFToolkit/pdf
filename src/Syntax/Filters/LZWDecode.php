<?php

declare(strict_types=1);

namespace PDF\Syntax\Filters;

use PDF\Specification\Dictionary\LZWDecodeAndFlateDecodeFiltersTable8;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** ISO7.4.4.2, physical PDF pages34–35: native PDF LZW, including both EarlyChange policies. */
final readonly class LZWDecode implements Filter
{
    public function __construct(public EarlyChange $earlyChange = new EarlyChange(), public ?Predictor $predictor = null)
    {
    }

    public function name(): NameType
    {
        return new NameType('LZWDecode');
    }

    public function parameters(): DictionaryType
    {
        return ($this->predictor?->parameters() ?? new LZWDecodeAndFlateDecodeFiltersTable8())->with(new Entry(new NameType('EarlyChange'), $this->earlyChange->value));
    }

    public function encode(string $bytes): string
    {
        $bytes = $this->predictor?->encode($bytes) ?? $bytes;
        $output = new LzwCodeStream();
        $table = new LzwEncodingTable($this->earlyChange);
        $output->write(new LzwCodeWord(new IntegerType(LzwControlCode::ClearTable->value), $table->bits()));
        if ($bytes !== '') {
            $current = ord($bytes[0]);
            for ($position = 1; $position < strlen($bytes); ++$position) {
                $nextByte = ord($bytes[$position]);
                $known = $table->lookup($current, $nextByte);
                if ($known !== null) {
                    $current = $known;
                    continue;
                }
                $output->write(new LzwCodeWord(new IntegerType($current), $table->bits()));
                if ($table->append($current, $nextByte)) {
                    $output->write(new LzwCodeWord(new IntegerType(LzwControlCode::ClearTable->value), $table->bits()));
                    $table->clear();
                }
                $current = $nextByte;
            }
            $output->write(new LzwCodeWord(new IntegerType($current), $table->bits()));
        }
        $output->write(new LzwCodeWord(new IntegerType(LzwControlCode::EndOfData->value), $table->endMarkerBits()));
        return $output->bytes();
    }
}
