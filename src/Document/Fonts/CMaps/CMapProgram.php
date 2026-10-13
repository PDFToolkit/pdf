<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

/** Writer for the supported PDF CMap operator subset; no PostScript program is executed. */
final readonly class CMapProgram
{
    public function __construct(private CMapName $name, private CMapSystemInfo $systemInfo, private CodeSpaces $spaces, private CidMappings|UnicodeMappings $mappings, private CMapWritingMode $mode = CMapWritingMode::Horizontal, private ?CMapName $base = null)
    {
    }

    public function bytes(): string
    {
        $lines = ['/CIDInit /ProcSet findresource begin', '12 dict begin', 'begincmap'];
        if ($this->base !== null) {
            $lines[] = $this->base->program() . ' usecmap';
        }
        $lines[] = '/CIDSystemInfo ' . $this->systemInfo->program() . ' def';
        $lines[] = '/CMapName ' . $this->name->program() . ' def';
        $lines[] = '/CMapType ' . ($this->mappings instanceof CidMappings ? '1' : '2') . ' def';
        $lines[] = '/WMode ' . $this->mode->value . ' def';
        foreach (array_chunk($this->spaces->ranges, 100) as $chunk) {
            $lines[] = count($chunk) . ' begincodespacerange';
            foreach ($chunk as $range) {
                $lines[] = $range->low->hex() . ' ' . $range->high->hex();
            }
            $lines[] = 'endcodespacerange';
        }
        array_push($lines, ...[...$this->mappings->program(), 'endcmap', 'CMapName currentdict /CMap defineresource pop', 'end', 'end']);
        return implode("\n", $lines) . "\n";
    }
}
