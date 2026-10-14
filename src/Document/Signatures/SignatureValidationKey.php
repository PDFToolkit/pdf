<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** Table 261: uppercase SHA1 identifier over the actual Contents bytes, including zero padding. */
final readonly class SignatureValidationKey
{
    public NameType $name;

    public function __construct(SignedRevision $signature)
    {
        foreach ($signature->revision->objects() as $record) {
            $dictionary = $record->dictionary;
            $contents = $dictionary?->get('Contents');
            $range = $dictionary?->get('ByteRange');
            if (!$contents instanceof StringType || !$range instanceof ArrayType || count($range->items) !== 4) {
                continue;
            }
            $numbers = [];
            foreach ($range->items as $part) {
                if (!$part instanceof IntegerType) {
                    continue 2;
                }
                $numbers[] = $part->integer();
            }
            if ($numbers === [0, $signature->range->before, $signature->range->afterOffset, $signature->range->afterLength]) {
                $lexical = substr($signature->revision->bytes, $signature->range->before, $signature->range->afterOffset - $signature->range->before);
                if ($lexical !== '<' . strtoupper(bin2hex($contents->value)) . '>') {
                    throw new \InvalidArgumentException('VRI keys bind the actual finalized Contents reservation.');
                }
                $this->name = new NameType(strtoupper(sha1($contents->value)));
                return;
            }
        }
        throw new \InvalidArgumentException('A VRI key requires the completed signature dictionary in its frozen revision.');
    }
}
