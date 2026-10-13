<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Syntax\Object\StringObject\HexadecimalString;

/** Source codes supplied explicitly by the application; each remains a separate object. */
final readonly class CMapCodeSequence
{
    /** @var list<CMapCode> */
    public array $codes;

    public function __construct(CodeSpaces $spaces, CMapCode ...$codes)
    {
        foreach ($codes as $code) {
            if (!$spaces->contains($code)) {
                throw new \InvalidArgumentException('Every shown character code belongs to its encoding codespace.');
            }
        }
        $this->codes = array_values($codes);
    }

    public function string(): HexadecimalString
    {
        return new HexadecimalString(implode('', array_map(static fn (CMapCode $code) => $code->value->value, $this->codes)));
    }
}
