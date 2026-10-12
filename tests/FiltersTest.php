<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\Filters\ASCII85Decode;
use PDF\Syntax\Filters\ASCIIHexDecode;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Filters\RunLengthDecode;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\TestCase;

final class FiltersTest extends TestCase
{
    public function testKnownAscii85VectorsAndPartialGroups(): void
    {
        $filter = new ASCII85Decode();
        self::assertSame('~>', $filter->encode(''));
        self::assertSame('z~>', $filter->encode("\0\0\0\0"));
        self::assertSame('!!~>', $filter->encode("\0"));
        self::assertSame('87cURD]j7BEbo80~>', $filter->encode('Hello world!'));
    }

    public function testRunLengthRoundTripsAcrossPacketBoundaries(): void
    {
        $input = str_repeat('a', 300) . implode('', array_map(chr(...), range(0, 255))) . 'bcdd' . str_repeat('x', 128);
        $encoded = (new RunLengthDecode())->encode($input);
        $decoded = '';
        for ($offset = 0; ord($encoded[$offset]) !== 128;) {
            $length = ord($encoded[$offset++]);
            if ($length < 128) {
                $decoded .= substr($encoded, $offset, $length + 1);
                $offset += $length + 1;
            } else {
                $decoded .= str_repeat($encoded[$offset++], 257 - $length);
            }
        }
        self::assertSame($input, $decoded);
    }

    public function testFilterOrderAndLengthDescribeEncodedData(): void
    {
        $context = new SerializationContext();
        $input = str_repeat("abc\0\xff", 100);
        $stream = new StreamObject($input, null, new ASCIIHexDecode(), new FlateDecode());
        $encoded = $stream->bytes($context);
        self::assertSame($input, gzuncompress(hex2bin(substr($encoded, 0, -1))));
        self::assertStringContainsString('/Filter [/ASCIIHexDecode /FlateDecode]', $stream->toPdf($context));
        self::assertStringContainsString('/Length ' . strlen($encoded), $stream->toPdf($context));
    }
}
