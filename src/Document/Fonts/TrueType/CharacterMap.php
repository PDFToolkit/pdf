<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\TrueType;

/** Immutable repertoire, declared before the PDF graph and its content are built. */
final readonly class CharacterMap
{
    /** @var array<int, CharacterMapping> */
    private array $characters;
    /** @var list<CharacterMapping> */
    public array $mappings;

    public function __construct(public FontProgram $program, UnicodeText $repertoire)
    {
        $characters = [];
        foreach ($repertoire->codePoints as $point) {
            if (isset($characters[$point])) {
                continue;
            }
            if (count($characters) === 0xFFFF) {
                throw new \InvalidArgumentException('Identity-H supports at most 65535 distinct mapped characters.');
            }
            $glyph = $program->cmap->glyphId($point);
            if ($glyph === 0) {
                throw new \InvalidArgumentException(sprintf('Font has no glyph for U+%04X.', $point));
            }
            $characters[$point] = new CharacterMapping($point, count($characters) + 1, $glyph, $program->metrics->pdfWidth($glyph));
        }
        $this->characters = $characters;
        $this->mappings = array_values($characters);
    }

    public function character(int $codePoint): CharacterMapping
    {
        return $this->characters[$codePoint] ?? throw new \OutOfRangeException(sprintf('U+%04X is not in this font repertoire.', $codePoint));
    }
}
