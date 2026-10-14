<?php

declare(strict_types=1);

namespace PDF\Syntax\DataStructure;

use PDF\Syntax\Object\StringType;

/**
 * PDF date values only: ISO_32000-2_sponsored_EC3.pdf,7.9.4, physical pp.133–134.
 * Missing trailing components retain the specified defaults. NOTE2 on p.134
 * recommends accepting the terminal apostrophe used by PDF1.7 and earlier.
 * Validation preserves the supplied value; it does not normalize or convert timezones.
 */
final readonly class PdfDateValidation
{
    public function __construct(private TextStringDecoder $decoder = new TextStringDecoder())
    {
    }

    public function validate(StringType $date): void
    {
        // Longest value:23 ASCII characters; UTF16BE with its BOM occupies48 bytes.
        if (strlen($date->value) > 48) {
            throw new \InvalidArgumentException('A PDF date exceeds the bounded date-string syntax.');
        }
        $text = $this->decoder->decode($date);
        if (strlen($text) > 23 || preg_match("~\\AD:(?<local>[0-9]{4}(?:[0-9]{2}){0,5})(?:(?<zone>[Z+-])(?:(?<offsetHour>[0-9]{2})(?:'(?:(?<offsetMinute>[0-9]{2})'?)?)?)?)?\\z~D", $text, $fields, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new \InvalidArgumentException('A PDF date requires D:, a four-digit year and successive complete components.');
        }
        $local = $fields['local'];
        $year = (int) substr($local, 0, 4);
        $month = $this->component($local, 4, 1);
        $day = $this->component($local, 6, 1);
        $hour = $this->component($local, 8, 0);
        $minute = $this->component($local, 10, 0);
        $second = $this->component($local, 12, 0);
        $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
        $days = [1 => 31, 28 + (int) $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($month < 1 || $month > 12 || $day < 1 || $day > $days[$month]
            || $hour > 23 || $minute > 59 || $second > 59) {
            throw new \InvalidArgumentException('A PDF date requires possible Gregorian components and valid clock ranges.');
        }
        if ($fields['zone'] !== null) {
            if (strlen($local) !== 14) {
                throw new \InvalidArgumentException('A PDF date timezone follows all preceding date and time components.');
            }
            $offsetHour = (int) ($fields['offsetHour'] ?? 0);
            $offsetMinute = (int) ($fields['offsetMinute'] ?? 0);
            if ($offsetHour > 23 || $offsetMinute > 59 || ($fields['zone'] === 'Z' && ($offsetHour !== 0 || $offsetMinute !== 0))) {
                throw new \InvalidArgumentException('A PDF date requires valid offset ranges and a zero offset with Z.');
            }
        }
    }

    private function component(string $local, int $offset, int $default): int
    {
        return strlen($local) > $offset ? (int) substr($local, $offset, 2) : $default;
    }
}
