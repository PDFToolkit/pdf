<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Security\SignatureDerElement;

/** RFC 3161 2.4.2: absent components contribute zero to the UTC deviation. */
final readonly class TimestampAccuracy
{
    public function __construct(public int $seconds = 0, public int $milliseconds = 0, public int $microseconds = 0)
    {
        if ($seconds < 0 || $milliseconds < 0 || $milliseconds > 999 || $microseconds < 0 || $microseconds > 999) {
            throw new \InvalidArgumentException('Timestamp accuracy has nonnegative seconds and at most 999 milliseconds or microseconds.');
        }
    }

    public static function fromDer(SignatureDerElement $element): self
    {
        $values = [0, 0, 0];
        $previous = -1;
        foreach ($element->requireTag(48)->children() as $field) {
            $order = match ($field->tag) {
                2 => 0, 128 => 1, 129 => 2, default => throw new \InvalidArgumentException('Timestamp accuracy contains only seconds, millis and micros.')
            };
            if ($order <= $previous) {
                throw new \InvalidArgumentException('Timestamp accuracy components are unique and in ASN.1 order.');
            }
            $previous = $order;
            $bytes = $field->value;
            if ($bytes === '' || (ord($bytes[0]) & 128) !== 0 || (strlen($bytes) > 1 && $bytes[0] === "\0" && (ord($bytes[1]) & 128) === 0)) {
                throw new \InvalidArgumentException('Timestamp accuracy uses minimally encoded nonnegative DER integers.');
            }
            $value = 0;
            for ($index = 0; $index < strlen($bytes); ++$index) {
                $byte = ord($bytes[$index]);
                if ($value > intdiv(PHP_INT_MAX - $byte, 256)) {
                    throw new \LengthException('Timestamp accuracy seconds exceed the selected native integer processor.');
                }
                $value = $value * 256 + $byte;
            }
            if ($order > 0 && ($value < 1 || $value > 999)) {
                throw new \InvalidArgumentException('Present timestamp millis and micros lie between one and 999.');
            }
            $values[$order] = $value;
        }
        return new self(...$values);
    }
}
