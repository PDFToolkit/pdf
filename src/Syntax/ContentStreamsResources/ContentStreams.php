<?php

declare(strict_types=1);

namespace PDF\Syntax\ContentStreamsResources;

use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\Operators\InlineImage;
use PDF\Syntax\Object\BooleanType;

/**
 * Ordered object instructions; not an indirect object or stream dictionary.
 *
 * A StreamObject owns these bytes. Operator contexts follow ISO 32000-1,
 * Figure 9; resource resolution and colour-space component counts are external.
 *
 * @implements \IteratorAggregate<int, Operator>
 * @phpstan-consistent-constructor
 */
readonly class ContentStreams implements \Stringable, \Countable, \IteratorAggregate
{
    /** @var list<Operator> */
    public array $operators;

    public function __construct(Operator ...$operators)
    {
        $this->operators = array_values($operators);
    }

    #[\NoDiscard]
    public function with(Operator ...$operators): static
    {
        return new static(...[...$this->operators, ...$operators]);
    }

    public function count(): int
    {
        return count($this->operators);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->operators;
    }

    protected function isType3Glyph(): bool
    {
        return false;
    }

    /** Validate nesting and the state-dependent syntax before emitting bytes. */
    public function validate(): void
    {
        $text = false;
        $path = false;
        $clip = false;
        $graphicsDepth = 0;
        $markedDepth = 0;
        $compatibilityDepth = 0;
        $nestedScopes = [];
        $type3 = $this->isType3Glyph();
        $uncoloredGlyph = false;
        if ($type3) {
            $first = isset($this->operators[0]) ? $this->operators[0]->symbol() : '';
            if (!in_array($first, ['d0', 'd1'], true)) {
                throw new \LogicException('A Type 3 glyph stream must begin with d0 or d1.');
            }
            $uncoloredGlyph = $first === 'd1';
        }
        $construction = ['m', 'l', 'c', 'v', 'y', 'h', 're'];
        $painting = ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'n'];
        $positioningAndShowing = ['Td', 'TD', 'Tm', 'T*', 'Tj', 'TJ', "'", '"'];
        $colors = ['CS', 'cs', 'SC', 'sc', 'SCN', 'scn', 'G', 'g', 'RG', 'rg', 'K', 'k'];
        foreach ($this->operators as $index => $operator) {
            $symbol = $operator->symbol();
            if (in_array($symbol, ['d0', 'd1'], true)) {
                if (!$type3 || $index !== 0) {
                    throw new \LogicException('Glyph metrics are permitted only as the first instruction in a Type 3 glyph stream.');
                }
                continue;
            }
            if ($uncoloredGlyph && (in_array($symbol, $colors, true) || $symbol === 'sh')) {
                throw new \LogicException('An uncolored Type 3 glyph must not specify colours.');
            }
            if ($uncoloredGlyph && $operator instanceof InlineImage) {
                $mask = $operator->dictionary->get('ImageMask') ?? $operator->dictionary->get('IM');
                if (!$mask instanceof BooleanType || !$mask->value) {
                    throw new \LogicException('An uncolored Type 3 glyph may contain only image masks.');
                }
            }
            if ($clip && !in_array($symbol, $painting, true)) {
                throw new \LogicException('A clipping operator must be immediately followed by a path-painting operator.');
            }
            if ($path && !in_array($symbol, [...$construction, ...$painting, 'W', 'W*'], true)) {
                throw new \LogicException('Only path construction, clipping and painting are permitted inside a path object.');
            }
            if (in_array($symbol, $construction, true)) {
                if ($text) {
                    throw new \LogicException('Path construction is prohibited inside a text object.');
                }
                if (!$path && !in_array($symbol, ['m', 're'], true)) {
                    throw new \LogicException('A path must establish a current point with m or re.');
                }
                $path = true;
                continue;
            }
            if (in_array($symbol, ['W', 'W*'], true)) {
                if (!$path || $text) {
                    throw new \LogicException('Clipping requires a path outside a text object.');
                }
                $clip = true;
                continue;
            }
            if (in_array($symbol, $painting, true)) {
                if (!$path || $text) {
                    throw new \LogicException('Path painting requires a path outside a text object.');
                }
                $path = false;
                $clip = false;
                continue;
            }
            if ($text && in_array($symbol, ['q', 'Q', 'cm', 'Do', 'sh', 'BI'], true)) {
                throw new \LogicException('Special graphics state and image operators are prohibited inside a text object.');
            }
            if (in_array($symbol, $positioningAndShowing, true) && !$text) {
                throw new \LogicException('Text positioning and showing require BT and ET.');
            }
            switch ($symbol) {
                case 'BT':
                    if ($text) {
                        throw new \LogicException('Text objects cannot be nested.');
                    }
                    $nestedScopes[] = 'BT';
                    $text = true;
                    break;
                case 'ET':
                    if (!$text) {
                        throw new \LogicException('ET has no matching BT.');
                    }
                    if (array_pop($nestedScopes) !== 'BT') {
                        throw new \LogicException('Text and marked-content scopes must nest without crossing (14.6.1).');
                    }
                    $text = false;
                    break;
                case 'q':
                    ++$graphicsDepth;
                    break;
                case 'Q':
                    if ($graphicsDepth === 0) {
                        throw new \LogicException('Q has no matching q.');
                    }
                    --$graphicsDepth;
                    break;
                case 'BMC':
                case 'BDC':
                    $nestedScopes[] = 'marked';
                    ++$markedDepth;
                    break;
                case 'EMC':
                    if ($markedDepth === 0) {
                        throw new \LogicException('EMC has no matching BMC or BDC.');
                    }
                    if (array_pop($nestedScopes) !== 'marked') {
                        throw new \LogicException('Text and marked-content scopes must nest without crossing (14.6.1).');
                    }
                    --$markedDepth;
                    break;
                case 'BX':
                    ++$compatibilityDepth;
                    break;
                case 'EX':
                    if ($compatibilityDepth === 0) {
                        throw new \LogicException('EX has no matching BX.');
                    }
                    --$compatibilityDepth;
                    break;
            }
        }
        if ($text || $path || $clip || $graphicsDepth !== 0 || $markedDepth !== 0 || $compatibilityDepth !== 0) {
            throw new \LogicException('Content stream contains an unfinished text, path, graphics-state, marked-content or compatibility scope.');
        }
    }

    public function toPdf(SerializationContext $context): string
    {
        $this->validate();
        $lines = array_map(static fn (Operator $operator): string => $operator->toPdf($context), $this->operators);

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    public function __toString(): string
    {
        return $this->toPdf(new SerializationContext());
    }
}
