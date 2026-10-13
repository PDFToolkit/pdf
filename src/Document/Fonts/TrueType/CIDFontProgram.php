<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** 9.9: all glyphs retained; CID FontFile2 omits cmap and invalidated DSIG. */
final readonly class CIDFontProgram
{
    public string $bytes;
    /** @var array<string, FontTable> */
    public array $tables;

    public function __construct(public FontProgram $source)
    {
        $tables = [];
        foreach ($source->tables() as $tag => $reader) {
            if ($tag === 'cmap' || $tag === 'DSIG') {
                continue;
            }
            $bytes = $reader->slice(0, $reader->length());
            if ($tag === 'head') {
                $bytes = substr_replace($bytes, "\x00\x00\x00\x00", 8, 4);
            }
            $tables[$tag] = new FontTable($tag, $bytes);
        }
        // DSIG signs the original sfnt directory; the transformed program cannot retain it.
        ksort($tables, SORT_STRING);
        $count = count($tables);
        $selector = 0;
        while ((1 << ($selector + 1)) <= $count) {
            ++$selector;
        }
        $searchRange = 16 * (1 << $selector);
        $directory = pack('Nnnnn', 0x00010000, $count, $searchRange, $selector, $count * 16 - $searchRange);
        $offset = 12 + $count * 16;
        $data = '';
        $headOffset = null;
        foreach ($tables as $table) {
            $length = strlen($table->bytes);
            $directory .= $table->tag . pack('NNN', $table->checksum->value, $offset, $length);
            if ($table->tag === 'head') {
                $headOffset = $offset;
            }
            $padded = $table->bytes . str_repeat("\x00", (4 - $length % 4) % 4);
            $data .= $padded;
            $offset += strlen($padded);
        }
        if ($headOffset === null) {
            throw new \LogicException('The validated source program must contain head.');
        }
        $bytes = $directory . $data;
        $adjustment = (0xB1B0AFBA - (new SfntChecksum($bytes))->value) & 0xFFFFFFFF;
        $this->bytes = substr_replace($bytes, pack('N', $adjustment), $headOffset + 8, 4);
        $tables['head'] = new FontTable('head', substr_replace($tables['head']->bytes, pack('N', $adjustment), 8, 4));
        $this->tables = $tables;
    }
}
