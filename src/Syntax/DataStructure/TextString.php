<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\StringObject\LiteralString;

/** UTF-8 application text encoded as ASCII or UTF-16BE with BOM for PDF 1.x. */
final class TextString extends LiteralString
{
    public function __construct(public readonly string $text)
    {
        if (preg_match('//u', $text) !== 1) {
            throw new \InvalidArgumentException('A text string must contain valid UTF-8.');
        }
        if (preg_match('/^[\x09\x0a\x0d\x20-\x7e]*$/D', $text) === 1) {
            parent::__construct($text);

            return;
        }
        $encoded = iconv('UTF-8', 'UTF-16BE', $text);
        if ($encoded === false) {
            throw new \InvalidArgumentException('The text string could not be encoded as UTF-16BE.');
        }
        parent::__construct("\xfe\xff" . $encoded);
    }
}
