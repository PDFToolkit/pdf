<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;

/** ISO 12.7.3.3, PDF p.443: explicit text state, Tf and at most one Tm. */
final class DefaultAppearance extends LiteralString
{
    /** @var list<NameType> */
    private readonly array $fonts;
    /** @var list<Operator> */
    public readonly array $operators;

    public function __construct(Operator ...$operators)
    {
        $fonts = [];
        $matrices = 0;
        $context = new SerializationContext();
        $bytes = [];
        foreach ($operators as $operator) {
            if (!in_array($operator->symbol(), ['w', 'J', 'j', 'M', 'd', 'ri', 'i', 'gs', 'CS', 'cs', 'SC', 'SCN', 'sc', 'scn', 'G', 'g', 'RG', 'rg', 'K', 'k', 'Tc', 'Tw', 'Tz', 'TL', 'Tf', 'Tr', 'Ts', 'Tm'], true)) {
                throw new \InvalidArgumentException('Default appearances contain only graphics/text state operators permitted inside text objects.');
            }
            if ($operator->symbol() === 'Tm' && ++$matrices > 1) {
                throw new \InvalidArgumentException('A default appearance contains at most one text matrix.');
            }
            if ($operator instanceof SetTextFontAndSize) {
                if ($operator->size->value < 0) {
                    throw new \InvalidArgumentException('A default text size is nonnegative; zero explicitly requests viewer auto-sizing.');
                }
                $fonts[] = $operator->font;
            }
            $bytes[] = $operator->toPdf($context);
        }
        if ($fonts === []) {
            throw new \InvalidArgumentException('A default appearance requires a Tf operator.');
        }
        $this->fonts = $fonts;
        $this->operators = array_values($operators);
        parent::__construct(implode("\n", $bytes));
    }

    public function validateResources(DictionaryType $resources): void
    {
        $fonts = $resources->get('Font');
        $fonts = $fonts instanceof IndirectReference ? $fonts->target() : $fonts;
        if (!$fonts instanceof DictionaryType) {
            throw new \InvalidArgumentException('Default resources must contain a Font dictionary.');
        }
        foreach ($this->fonts as $font) {
            $target = $fonts->get($font);
            $target = $target instanceof IndirectReference ? $target->target() : $target;
            if (!$target instanceof DictionaryType) {
                throw new \InvalidArgumentException('A default appearance font must resolve through the form default resources.');
            }
        }
    }
}
