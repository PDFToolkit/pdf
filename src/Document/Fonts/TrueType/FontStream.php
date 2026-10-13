<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

use PDF\Syntax\Filters\EncodedData;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StreamObject;

/** Font data starts decoded; filters must actually encode the supplied bytes. */
abstract class FontStream extends StreamObject
{
    protected function __construct(string $bytes, ?DictionaryType $dictionary, Filter ...$filters)
    {
        foreach ($filters as $filter) {
            if ($filter instanceof EncodedData) {
                throw new \InvalidArgumentException('Font streams supply decoded bytes and cannot use EncodedData.');
            }
        }
        parent::__construct($bytes, $dictionary, ...$filters);
    }
}
