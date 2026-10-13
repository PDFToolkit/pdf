<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\Encodings;

use PDF\Document\Fonts\ManagedFontDictionary;
use PDF\Specification\Definition\EncodingDictionaryTable114Definition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Table 114: Differences applied to an explicitly named or supplied built-in vector. */
final class EncodingDictionary extends ManagedFontDictionary implements FontEncoding
{
    private readonly ?EncodingVector $baseVector;
    public function __construct(public readonly EncodingDifferences $differences, public readonly ?PredefinedEncoding $base = null, public readonly ?EncodingVector $builtIn = null)
    {
        if ($base !== null && $builtIn !== null) {
            throw new \InvalidArgumentException('An encoding dictionary selects one base vector.');
        }
        $this->baseVector = $base?->vector() ?? $builtIn;
        $entries = [new Entry(new NameType('Type'), new NameType('Encoding')), new Entry(new NameType('Differences'), $differences)];
        if ($base !== null) {
            $entries[] = new Entry(new NameType('BaseEncoding'), new NameType($base->value));
        }
        parent::__construct(new EncodingDictionaryTable114Definition(), ['Type', 'BaseEncoding', 'Differences'], ...$entries);
    }

    public function object(): self
    {
        return $this;
    }
    public function glyph(IntegerType $code): NameType
    {
        if ($code->integer() < 0 || $code->integer() > 255) {
            throw new \InvalidArgumentException('Simple-font character codes are bytes.');
        }
        $glyph = $this->differences->glyph($code);
        if ($glyph !== null) {
            return $glyph;
        }
        $base = $this->baseVector;
        if ($base === null) {
            throw new \LogicException('Resolving an unmodified code requires the externally supplied built-in font vector.');
        }
        return $base->glyph($code);
    }
}
