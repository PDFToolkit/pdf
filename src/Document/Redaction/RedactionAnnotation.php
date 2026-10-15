<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\Document\Forms\DefaultAppearance;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Adobe redaction addendum, physical pages 2–4: identification, not an applied overlay. */
final readonly class RedactionAnnotation
{
    /** @var non-empty-list<RedactionBounds> */
    public array $regions;
    /** @var null|array{float, float, float} */
    public ?array $interior;
    public RedactionBounds $rectangle;
    public ?StreamObject $overlay;
    public ?StringType $overlayText;
    public ?DefaultAppearance $defaultAppearance;
    public bool $repeat;
    public int $quadding;

    public function __construct(public DictionaryType $annotation)
    {
        $subtype = $this->value($annotation->get('Subtype'));
        if (!$subtype instanceof NameType || $subtype->value !== 'Redact') {
            throw new \InvalidArgumentException('An applied redaction requires Subtype=Redact.');
        }
        $rect = $this->numbers($annotation->get('Rect'), 4);
        $this->rectangle = new RedactionBounds(min($rect[0], $rect[2]), min($rect[1], $rect[3]), max($rect[0], $rect[2]), max($rect[1], $rect[3]));
        $quads = $this->value($annotation->get('QuadPoints'));
        $regions = [];
        if ($quads !== null) {
            if (!$quads instanceof ArrayType || count($quads) === 0 || count($quads) % 8 !== 0) {
                throw new \InvalidArgumentException('QuadPoints requires one or more complete eight-number quadrilaterals.');
            }
            foreach (array_chunk($this->numbers($quads, count($quads)), 8) as $quad) {
                $xs = [$quad[0], $quad[2], $quad[4], $quad[6]];
                $ys = [$quad[1], $quad[3], $quad[5], $quad[7]];
                $regions[] = new RedactionBounds(min($xs), min($ys), max($xs), max($ys));
            }
        } else {
            $regions[] = new RedactionBounds(min($rect[0], $rect[2]), min($rect[1], $rect[3]), max($rect[0], $rect[2]), max($rect[1], $rect[3]));
        }
        if ($regions === []) {
            throw new \LogicException('A redaction selection requires at least one geometric region.');
        }
        $this->regions = $regions;
        $overlay = $this->value($annotation->get('RO'));
        if ($overlay !== null) {
            $subtype = $overlay instanceof StreamObject ? $this->value($overlay->dictionary()->get('Subtype')) : null;
            if (!$overlay instanceof StreamObject || !$overlay->data instanceof ContentStreams || !$subtype instanceof NameType || $subtype->value !== 'Form') {
                throw new \InvalidArgumentException('A redaction RO must be a normalized, explicitly typed Form XObject.');
            }
            $this->overlay = $overlay;
            $this->overlayText = $this->defaultAppearance = $this->interior = null;
            $this->repeat = false;
            $this->quadding = 0;
            return; // RO takes precedence over even invalid ignored IC, OverlayText, DA and Q.
        }
        $this->overlay = null;
        $text = $this->value($annotation->get('OverlayText'));
        $appearance = $this->value($annotation->get('DA'));
        if ($text !== null && (!$text instanceof StringType || !$appearance instanceof DefaultAppearance)) {
            throw new \InvalidArgumentException('OverlayText requires a text string and a normalized typed DefaultAppearance.');
        }
        $this->overlayText = $text instanceof StringType ? $text : null;
        $this->defaultAppearance = $text === null ? null : ($appearance instanceof DefaultAppearance ? $appearance : null);
        $repeat = $this->value($annotation->get('Repeat'));
        $quadding = $this->value($annotation->get('Q'));
        if ($text !== null && (($repeat !== null && !$repeat instanceof BooleanType) || ($quadding !== null && (!$quadding instanceof IntegerType || !in_array($quadding->integer(), [0, 1, 2], true))))) {
            throw new \InvalidArgumentException('Repeat is boolean and redaction Q is 0, 1 or 2.');
        }
        $this->repeat = $repeat instanceof BooleanType && $repeat->value;
        $this->quadding = $quadding instanceof IntegerType ? $quadding->integer() : 0;
        $interior = $this->value($annotation->get('IC'));
        if ($interior === null) {
            $this->interior = null;
        } else {
            $values = $this->numbers($interior, 3);
            foreach ($values as $value) {
                if ($value < 0 || $value > 1) {
                    throw new \InvalidArgumentException('A redaction interior is a DeviceRGB triplet in [0,1].');
                }
            }
            $this->interior = [$values[0], $values[1], $values[2]];
        }
    }

    /** @return list<float> */
    private function numbers(?BasicType $value, int $count): array
    {
        $value = $this->value($value);
        if (!$value instanceof ArrayType || count($value) !== $count) {
            throw new \InvalidArgumentException('A redaction coordinate/color array has the wrong cardinality.');
        }
        $numbers = [];
        foreach ($value->items as $item) {
            $item = $this->value($item);
            if (!$item instanceof NumericType || !is_finite((float) $item->value)) {
                throw new \InvalidArgumentException('Redaction coordinates and colors are finite numbers.');
            }
            $numbers[] = (float) $item->value;
        }
        return $numbers;
    }

    private function value(?BasicType $value): ?BasicType
    {
        $value = $value instanceof IndirectReference ? $value->target() : $value;
        return $value instanceof NullType ? null : $value;
    }
}
