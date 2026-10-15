<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StringObject\LiteralString;
use PDF\Syntax\Object\NameType;

/** ToUnicode definition: arbitrary code sizes, scalar sequences, ranges and explicit inheritance. */
final readonly class UnicodeCMap
{
    public CodeSpaces $effectiveSpaces;
    public CMapSystemInfo $systemInfo;

    public function __construct(public CMapName $name, public CodeSpaces $spaces, public UnicodeMappings $mappings, public ?UnicodeCMapResource $base = null, ?CMapSystemInfo $systemInfo = null, public CMapWritingMode $mode = CMapWritingMode::Horizontal)
    {
        $this->systemInfo = $systemInfo ?? new CMapSystemInfo(new LiteralString('Adobe'), new LiteralString('UCS'), new IntegerType(0));
        $this->effectiveSpaces = new CodeSpaces(...[...($base?->codeSpaces()->ranges ?? []), ...$spaces->ranges]);
        if ($this->effectiveSpaces->ranges === [] || ($base !== null && $base->name()->value->value === $name->value->value)) {
            throw new \InvalidArgumentException('ToUnicode has a nonempty effective codespace and a distinct base resource name.');
        }
        $mappings->validate($this->effectiveSpaces);
    }

    public function program(): string
    {
        $programBase = $this->base !== null && $this->base->object() instanceof NameType ? $this->base->name() : null;
        return (new CMapProgram($this->name, $this->systemInfo, $this->effectiveSpaces, $this->mappings, $this->mode, $programBase))->bytes();
    }

    public function sequence(CMapCode $code): ?UnicodeSequence
    {
        if (!$this->effectiveSpaces->contains($code)) {
            throw new \InvalidArgumentException('A character code must belong to the ToUnicode codespace.');
        }
        $local = $this->mappings->sequence($code);
        return $local ?? ($this->base !== null && $this->base->codeSpaces()->contains($code) ? $this->base->sequence($code) : null);
    }
}
