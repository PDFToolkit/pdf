<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;

/** Bounded native frozen bytes: no predictors, callbacks, I/O or implicit filter backends. */
final readonly class ProtectedStreamDecoder
{
    public function __construct(private int $maximumBytes = 1048576)
    {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Protected decoding requires a positive byte limit.');
        }
    }

    public function decode(RevisionStreamSnapshot $stream): string
    {
        $bytes = $stream->encodedBytes;
        if (strlen($bytes) > $this->maximumBytes) {
            throw new \LogicException('Encoded protected content exceeds its byte limit.');
        }
        $filter = $stream->dictionary()->get('Filter');
        $parameters = $stream->dictionary()->get('DecodeParms');
        if ($filter instanceof ArrayType && count($filter->items) === 1) {
            $filter = $filter->items[0];
            if ($parameters instanceof ArrayType && count($parameters->items) === 1) {
                $parameters = $parameters->items[0];
            }
        }
        if ($parameters !== null && !$parameters instanceof NullType && !($parameters instanceof DictionaryType && count($parameters) === 0)) {
            throw new \LogicException('Appearance content decoding does not support predictors or indirect parameters.');
        }
        if ($filter === null || $filter instanceof NullType) {
            return $bytes;
        }
        if (!$filter instanceof NameType || $filter->value !== 'FlateDecode') {
            throw new \LogicException('Appearance content supports only plain bytes or a single FlateDecode filter.');
        }
        $decoder = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($decoder === false) {
            throw new \LogicException('Cannot initialise the bounded protected decoder.');
        }
        $result = '';
        $length = strlen($bytes);
        // Small input pieces also bound the temporary expansion before the output limit check.
        for ($offset = 0; $offset < $length; $offset += 64) {
            $part = @inflate_add($decoder, substr($bytes, $offset, 64), $offset + 64 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($part === false || strlen($part) > $this->maximumBytes - strlen($result)) {
                throw new \LogicException('Invalid or oversized Flate protected content.');
            }
            $result .= $part;
            if (inflate_get_status($decoder) === ZLIB_STREAM_END) {
                break;
            }
        }
        if (inflate_get_status($decoder) !== ZLIB_STREAM_END || inflate_get_read_len($decoder) !== $length) {
            throw new \LogicException('Flate protected content must contain exactly one complete compressed stream.');
        }
        return $result;
    }

}
