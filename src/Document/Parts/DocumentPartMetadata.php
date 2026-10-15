<?php

declare(strict_types=1);

namespace PDF\Document\Parts;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\DataStructure\XmlName;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/** ISO 32000-2 physical pp.852–853/14.12.4.2: application-specific DPM, independent of XMP Metadata. */
final class DocumentPartMetadata extends DictionaryType
{
    public function __construct(Entry ...$entries)
    {
        parent::__construct(...$entries);
        $pending = [[$this, false]];
        $seen = new \SplObjectStorage();
        $active = new \SplObjectStorage();
        while ($pending !== []) {
            [$value, $exit] = array_pop($pending);
            if ($value instanceof IndirectReference) {
                $value = $value->target();
            }
            if ($exit) {
                $active->offsetUnset($value);
                $seen->offsetSet($value);
                continue;
            }
            if ($active->offsetExists($value)) {
                throw new \InvalidArgumentException('DPM cannot contain a direct container cycle.');
            }
            if ($seen->offsetExists($value)) {
                continue;
            }
            if ($value instanceof DictionaryType || $value instanceof ArrayType) {
                $active->offsetSet($value);
                $pending[] = [$value, true];
            }
            if (count($seen) > 100000) {
                throw new \LengthException('DPM exceeds its explicit 100000-container construction bound.');
            }
            if ($value instanceof DictionaryType) {
                foreach ($value->entries() as $entry) {
                    new XmlName($entry->key->value);
                    $pending[] = [$entry->value, false];
                }
            } elseif ($value instanceof ArrayType) {
                foreach ($value as $item) {
                    $pending[] = [$item, false];
                }
            } elseif ($value instanceof NameType) {
                if (preg_match('//u', $value->value) !== 1) {
                    throw new \InvalidArgumentException('EC3 #86 DPM name values require valid UTF-8 bytes.');
                }
            } elseif (!$value instanceof StringType && !$value instanceof BooleanType && !$value instanceof NumericType) {
                throw new \InvalidArgumentException('DPM permits only text/date strings, UTF-8 names, arrays, dictionaries, booleans and numbers.');
            }
        }
    }
    public function with(Entry $entry): static
    {
        throw new \LogicException('Document-part metadata is constructor-controlled.');
    }
    public function without(NameType|string $key): static
    {
        throw new \LogicException('Document-part metadata is constructor-controlled.');
    }
}
