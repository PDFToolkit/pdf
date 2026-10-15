<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Document\ResourceDictionary;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\InlineImage;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/** One document-scoped conservative paint-removal interpreter. It never produces a masking-only result. */
final class RedactionContentProcessor
{
    /** @var \SplObjectStorage<BasicType, null> */
    public readonly \SplObjectStorage $tainted;
    public int $removed = 0;
    private int $operations = 0;
    private int $payloadBytes = 0;
    private readonly RedactionResourceCopy $resources;

    public function __construct(private readonly RedactionLimits $limits, private readonly RedactionTextBounds $text = new RedactionTextBounds(), ?RedactionResourceSanitizer $sanitizer = null)
    {
        $this->tainted = new \SplObjectStorage();
        $this->resources = new RedactionResourceCopy($limits, $sanitizer);
    }

    /** @param list<RedactionBounds> $regions */
    public function process(ContentStreams $content, DictionaryType $resources, array $regions, Matrix $initial = new Matrix(), int $depth = 0): RedactionStream
    {
        if ($depth > $this->limits->depth) {
            throw new \OverflowException('Nested redaction Forms exceed their explicit depth budget.');
        }
        $content->validate();
        $state = new RedactionGraphicsState($initial);
        $stack = [];
        $output = [];
        $path = [];
        $pathBounds = null;
        $clip = false;
        $textBlock = null;
        $textState = [];
        $textBounds = null;
        $unknownText = false;
        $usedXObjects = [];
        foreach ($content->operators as $operator) {
            if (++$this->operations > $this->limits->operations) {
                throw new \OverflowException('Redaction content exceeds its explicit instruction budget.');
            }
            $symbol = $operator->symbol();
            $values = $operator->operands;
            if (!in_array($symbol, ['q', 'Q', 'cm', 'w', 'J', 'j', 'M', 'd', 'ri', 'i', 'CS', 'cs', 'SC', 'sc', 'G', 'g', 'RG', 'rg', 'K', 'k', 'm', 'l', 'c', 'v', 'y', 'h', 're', 'W', 'W*', 'S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'n', 'BT', 'ET', 'Tf', 'Tc', 'Tw', 'Tz', 'TL', 'Tr', 'Ts', 'Tm', 'Td', 'TD', 'T*', 'Tj', 'TJ', "'", '"', 'BMC', 'BDC', 'EMC', 'MP', 'DP', 'BI', 'Do'], true)) {
                throw new \InvalidArgumentException('Unsupported redaction content operator ' . $symbol . '; no result may be emitted.');
            }
            if (in_array($symbol, ['CS', 'cs'], true) && (!(($values[0] ?? null) instanceof NameType) || !in_array($values[0]->value, ['DeviceGray', 'DeviceRGB', 'DeviceCMYK'], true))) {
                throw new \InvalidArgumentException('Redaction of named, pattern or special color spaces requires explicit normalization.');
            }
            if (in_array($symbol, ['BMC', 'BDC', 'MP', 'DP', 'EMC'], true)) {
                if ($symbol === 'BDC' && ($values[0] ?? null) instanceof NameType && $values[0]->value === 'OC') {
                    throw new \InvalidArgumentException('Optional-content visibility must be explicitly normalized before redaction.');
                }
                continue; // Remove ActualText/Alt/properties/MCID channels along with the discarded structure tree.
            }
            if ($symbol === 'q') {
                $stack[] = clone $state;
            } elseif ($symbol === 'Q') {
                $state = array_pop($stack) ?? throw new \InvalidArgumentException('Unbalanced redaction graphics state.');
            } elseif ($symbol === 'cm') {
                $state->matrix = $this->matrix($values)->then($state->matrix);
            } elseif ($symbol === 'w') {
                $state->lineWidth = abs($this->number($values[0]));
            } elseif ($symbol === 'M') {
                $state->miterLimit = max(1, abs($this->number($values[0])));
            }
            if (in_array($symbol, ['m', 'l', 'c', 'v', 'y', 'h', 're'], true)) {
                $path[] = $operator;
                if ($symbol === 'h') {
                    continue;
                }
                $coordinates = array_map(fn (BasicType $value): float => $this->number($value), $values);
                if ($symbol === 're') {
                    $coordinates = [$coordinates[0], $coordinates[1], $coordinates[0] + $coordinates[2], $coordinates[1] + $coordinates[3]];
                }
                foreach (array_chunk($coordinates, 2) as [$x, $y]) {
                    $bound = (new RedactionBounds($x, $y, $x, $y))->transformed($state->matrix);
                    $pathBounds = $pathBounds === null ? $bound : $pathBounds->union($bound);
                }
                continue;
            }
            if (in_array($symbol, ['W', 'W*'], true)) {
                $clip = true;
                $path[] = $operator;
                continue;
            }
            if (in_array($symbol, ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*', 'n'], true)) {
                if ($pathBounds === null) {
                    throw new \InvalidArgumentException('A painted path must have a computable control-point hull.');
                }
                $bounds = in_array($symbol, ['S', 's', 'B', 'B*', 'b', 'b*'], true) ? $pathBounds->expanded(max(1.0, $state->lineWidth * $state->miterLimit * $this->text->scale($state->matrix))) : $pathBounds;
                $hit = $this->hits($bounds, $regions) || ($regions !== [] && $state->lineWidth === 0.0 && in_array($symbol, ['S', 's', 'B', 'B*', 'b', 'b*'], true));
                if ($clip && $hit) {
                    throw new \InvalidArgumentException('A clipping path crossing a redaction requires explicit normalization; retaining its original geometry would preserve redacted data.');
                }
                if ($symbol === 'n' || !$hit) {
                    array_push($output, ...[...$path, $operator]);
                } else {
                    ++$this->removed;
                }
                $path = [];
                $pathBounds = null;
                $clip = false;
                continue;
            }
            $this->text->state($operator, $state, $resources);
            if ($symbol === 'BT') {
                $textBlock = [$operator];
                $textState = [];
                $textBounds = null;
                $unknownText = false;
                continue;
            }
            if ($symbol === 'Tr' && $state->textMode >= 4) {
                throw new \InvalidArgumentException('Text clipping requires explicit normalization before redaction.');
            }
            if ($textBlock !== null) {
                $textBlock[] = $operator;
                if (in_array($symbol, ['Tf', 'Tc', 'Tw', 'Tz', 'TL', 'Ts', 'Tr', 'w', 'J', 'j', 'M', 'd', 'ri', 'i', 'CS', 'cs', 'SC', 'sc', 'G', 'g', 'RG', 'rg', 'K', 'k'], true)) {
                    $textState[] = $operator;
                }
                if (in_array($symbol, ['Tj', 'TJ', "'", '"'], true)) {
                    $bounds = $this->text->showing($operator, $state);
                    $unknownText = $unknownText || $bounds === null;
                    if ($bounds !== null) {
                        $textBounds = $textBounds === null ? $bounds : $textBounds->union($bounds);
                    }
                    if ($symbol === '"') {
                        // Quote changes word and character spacing that survive ET.
                        $textState[] = new \PDF\Syntax\ContentStreamsResources\Operators\SetWordSpacing(new NumericType($state->wordSpacing));
                        $textState[] = new \PDF\Syntax\ContentStreamsResources\Operators\SetCharacterSpacing(new NumericType($state->characterSpacing));
                    }
                }
                if ($symbol === 'ET') {
                    if ($regions !== [] && ($unknownText || ($textBounds !== null && $this->hits($textBounds, $regions)))) {
                        ++$this->removed;
                        // Keep only necessary persistent state, never any source string or positioning operand.
                        array_push($output, ...[new BeginTextObject(), ...$textState, new EndTextObject()]);
                    } else {
                        array_push($output, ...$textBlock);
                    }
                    $textBlock = null;
                }
                continue;
            }
            if ($symbol === 'BI' && $operator instanceof InlineImage) {
                $this->payloadBytes += strlen($operator->data);
                if ($this->payloadBytes > $this->limits->bytes) {
                    throw new \OverflowException('Inline redaction payloads exceed their explicit byte budget.');
                }
                if ($this->hits((new RedactionBounds(0, 0, 1, 1))->transformed($state->matrix), $regions)) {
                    ++$this->removed;
                    continue;
                }
                if ($operator->dictionary->has('Filter') || $operator->dictionary->has('F')) {
                    throw new \InvalidArgumentException('Retained inline images must first be decoded to exact raw pixel rows.');
                }
                $inlineDictionary = $this->resources->copy($operator->dictionary);
                if (!$inlineDictionary instanceof DictionaryType) {
                    throw new \LogicException('A normalized inline image requires a dictionary.');
                }
                $operator = new InlineImage($inlineDictionary, $operator->data);
            }
            if ($symbol === 'Do') {
                $name = $values[0] ?? null;
                $xobjects = $this->value($resources->get('XObject'));
                $object = $name instanceof NameType && $xobjects instanceof DictionaryType ? $this->value($xobjects->get($name)) : null;
                if (!$object instanceof StreamObject) {
                    throw new \InvalidArgumentException('An invoked redaction XObject must resolve to an explicit stream.');
                }
                $dictionary = $object->dictionary();
                if ($dictionary->has('OC') || $dictionary->has('Ref') || $dictionary->has('OPI')) {
                    throw new \InvalidArgumentException('Optional, external or alternate XObject content must be normalized before redaction.');
                }
                $subtype = $this->value($dictionary->get('Subtype'));
                if (!$subtype instanceof NameType || !in_array($subtype->value, ['Image', 'Form'], true)) {
                    throw new \InvalidArgumentException('Redaction supports only explicit Image and typed Form XObjects.');
                }
                $matrix = $state->matrix;
                $bounds = new RedactionBounds(0, 0, 1, 1);
                if ($subtype->value === 'Form') {
                    $bounds = $this->bounds($dictionary->get('BBox'));
                    $formMatrix = $this->value($dictionary->get('Matrix'));
                    if ($formMatrix instanceof ArrayType) {
                        $matrix = $this->matrix($formMatrix->items)->then($matrix);
                    } elseif ($formMatrix !== null && !$formMatrix instanceof \PDF\Syntax\Object\NullType) {
                        throw new \InvalidArgumentException('A retained Form Matrix must be a normalized numeric array.');
                    }
                }
                if ($this->hits($bounds->transformed($matrix), $regions)) {
                    $this->taint($object);
                }
                if ($this->tainted->offsetExists($object)) {
                    ++$this->removed;
                    continue;
                }
                if ($subtype->value === 'Form') {
                    if (!$object->data instanceof ContentStreams) {
                        throw new \InvalidArgumentException('A retained Form must have explicit typed instructions.');
                    }
                    $local = $this->value($dictionary->get('Resources'));
                    $nested = $this->process($object->data, $local instanceof DictionaryType ? $local : $resources, [], $matrix, $depth + 1);
                    $entries = [new Entry(new NameType('Type'), new NameType('XObject')), new Entry(new NameType('Subtype'), new NameType('Form')), new Entry(new NameType('BBox'), $this->bounds($dictionary->get('BBox'))->rectangle()), new Entry(new NameType('Resources'), $nested->resources)];
                    foreach (['Matrix', 'Group'] as $key) {
                        $value = $dictionary->get($key);
                        if ($value !== null) {
                            $entries[] = new Entry(new NameType($key), $this->resources->copy($value));
                        }
                    }
                    $usedXObjects[$name->value] = new StreamObject($nested->content, new DictionaryType(...$entries));
                } else {
                    $usedXObjects[$name->value] = $this->resources->copy($object);
                }
            }
            $output[] = $operator;
        }
        // A Tf retained only for persistent interpreter state does not justify
        // carrying an otherwise unused font program into a redacted document.
        $paintedFonts = [];
        $fontName = null;
        $fontStack = [];
        foreach ($output as $instruction) {
            if ($instruction->symbol() === 'q') {
                $fontStack[] = $fontName;
            } elseif ($instruction->symbol() === 'Q') {
                $fontName = array_pop($fontStack);
            } elseif ($instruction->symbol() === 'Tf' && ($instruction->operands[0] ?? null) instanceof NameType) {
                $fontName = $instruction->operands[0]->value;
            } elseif (in_array($instruction->symbol(), ['Tj', 'TJ', "'", '"'], true)) {
                if ($fontName === null) {
                    throw new \InvalidArgumentException('Retained text requires an explicit local Tf selection; inherited Form text state must first be normalized.');
                }
                $paintedFonts[$fontName] = true;
            }
        }
        $output = array_values(array_filter($output, static fn (Operator $instruction): bool => $instruction->symbol() !== 'Tf' || (($instruction->operands[0] ?? null) instanceof NameType && isset($paintedFonts[$instruction->operands[0]->value]))));
        $usedFonts = $paintedFonts;
        $entries = [];
        $fonts = $this->value($resources->get('Font'));
        $fontEntries = [];
        foreach ($usedFonts as $name => $_) {
            $font = $fonts instanceof DictionaryType ? $fonts->get($name) : null;
            if ($font === null) {
                throw new \InvalidArgumentException('A retained font selection has no local resource.');
            }
            $fontEntries[] = new Entry(new NameType($name), $this->resources->font($font));
        }
        if ($fontEntries !== []) {
            $entries[] = new Entry(new NameType('Font'), new DictionaryType(...$fontEntries));
        }
        if ($usedXObjects !== []) {
            $items = [];
            foreach ($usedXObjects as $name => $object) {
                $items[] = new Entry(new NameType($name), new IndirectReference($object));
            }
            $entries[] = new Entry(new NameType('XObject'), new DictionaryType(...$items));
        }
        $result = new ContentStreams(...$output);
        $result->validate();
        return new RedactionStream($result, new ResourceDictionary(...$entries));
    }

    /** @param list<RedactionBounds> $regions */
    private function hits(RedactionBounds $bounds, array $regions): bool
    {
        foreach ($regions as $region) {
            if ($bounds->intersects($region)) {
                return true;
            }
        }
        return false;
    }

    /** An affected Form/image cannot survive through a shared resource elsewhere in the fresh graph. */
    private function taint(BasicType $object): void
    {
        $queue = [$object];
        $seen = new \SplObjectStorage();
        while ($queue !== []) {
            $item = array_pop($queue);
            if ($seen->offsetExists($item)) {
                continue;
            }
            $seen->attach($item);
            if (count($seen) > $this->limits->objects) {
                throw new \OverflowException('Affected resource closure exceeds its explicit graph budget.');
            }
            if ($item instanceof StreamObject) {
                $subtype = $this->value($item->dictionary()->get('Subtype'));
                if ($subtype instanceof NameType && in_array($subtype->value, ['Image', 'Form'], true)) {
                    $this->tainted->attach($item);
                }
                $queue[] = $item->dictionary();
            } elseif ($item instanceof IndirectReference) {
                $queue[] = $item->target();
            } elseif ($item instanceof ArrayType) {
                array_push($queue, ...$item->items);
            } elseif ($item instanceof DictionaryType) {
                foreach ($item->entries() as $entry) {
                    $queue[] = $entry->value;
                }
            }
        }
    }

    public function bounds(?BasicType $value): RedactionBounds
    {
        $value = $this->value($value);
        if (!$value instanceof ArrayType || count($value) !== 4) {
            throw new \InvalidArgumentException('A Form bounding box requires four finite numbers.');
        }
        $v = array_map(fn (BasicType $item): float => $this->number($item), $value->items);
        return new RedactionBounds(min($v[0], $v[2]), min($v[1], $v[3]), max($v[0], $v[2]), max($v[1], $v[3]));
    }

    /** @param list<BasicType> $values */
    private function matrix(array $values): Matrix
    {
        if (count($values) !== 6) {
            throw new \InvalidArgumentException('An affine matrix requires six numeric operands.');
        }
        return new Matrix(...array_map(fn (BasicType $value): NumericType => new NumericType($this->number($value)), $values));
    }

    private function number(BasicType $value): float
    {
        $value = $this->value($value);
        return $value instanceof NumericType && is_finite((float) $value->value) ? (float) $value->value : throw new \InvalidArgumentException('Redaction geometry requires finite numbers.');
    }

    private function value(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }
}
