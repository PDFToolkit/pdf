<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\Annotations\AnnotationPageIndex;
use PDF\Document\DocumentGraphContext;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Intrinsic action contracts, not action execution (ISO2 12.6.4/12.7.6 pp531–543,567–571). */
final class ActionDictionaryRules
{
    private ?AnnotationPageIndex $annotations = null;
    /** @var array<string,true>|null */
    private ?array $optionalGroups = null;

    public function __construct(private readonly DocumentGraphContext $graph, private readonly ?EffectivePdfVersion $version, private readonly NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function validate(DictionaryType $dictionary, string $kind): void
    {
        $minimum = match ($kind) {
            'Launch', 'Thread', 'URI' => PdfVersion::PDF11,
            'Sound', 'Movie', 'Hide', 'Named', 'SubmitForm', 'ResetForm', 'ImportData' => PdfVersion::PDF12,
            'JavaScript' => PdfVersion::PDF13,
            'SetOCGState', 'Rendition', 'Trans' => PdfVersion::PDF15,
            'GoTo3DView' => PdfVersion::PDF16,
            'GoToDp', 'RichMediaExecute' => PdfVersion::PDF20,
            default => null,
        };
        if ($minimum === null) {
            return; // GoTo families have their own graph rules; extension action names remain opaque.
        }
        $this->version?->require($minimum, $kind . ' action');
        $values = new ActionDictionaryValues($dictionary, $this->graph);
        match ($kind) {
            'URI' => $this->uri($values),
            'Named' => $values->name('N', required: true),
            'JavaScript' => $values->typed('JS', [StringType::class, StreamObject::class], true),
            'Launch' => $this->launch($values),
            'Thread' => $this->thread($values),
            'Sound' => $this->sound($values),
            'Movie' => $this->movie($values),
            'Hide' => $this->hide($values),
            'SetOCGState' => $this->optionalContent($values),
            'Rendition' => $this->rendition($values),
            'Trans' => $this->transition($values),
            'GoTo3DView' => $this->threeD($values),
            'GoToDp' => $this->documentPart($values),
            'RichMediaExecute' => $this->richMedia($values),
            'SubmitForm', 'ResetForm' => $this->form($values, $kind === 'SubmitForm'),
            'ImportData' => $values->file('F', true),
        };
    }

    private function uri(ActionDictionaryValues $values): void
    {
        $values->typed('URI', [StringType::class], true);
        $values->typed('IsMap', [BooleanType::class]);
    }

    private function launch(ActionDictionaryValues $values): void
    {
        $required = $values->entry('Win') === null && $values->entry('Mac') === null && $values->entry('Unix') === null;
        $values->file('F', $required);
        $window = $values->typed('NewWindow', [BooleanType::class]);
        if ($window !== null) {
            $this->version?->require(PdfVersion::PDF12, 'Launch NewWindow');
        }
        $win = $values->child('Win');
        if ($win !== null) {
            $win->typed('F', [StringType::class], true);
            $win->typed('D', [StringType::class]);
            $win->typed('P', [StringType::class]);
            $operation = $win->typed('O', [StringType::class]);
            if ($operation instanceof StringType && !in_array($operation->value, ['open', 'print'], true)) {
                throw new \InvalidArgumentException('Launch Win.O must be the string open or print.');
            }
        }
    }

    private function thread(ActionDictionaryValues $values): void
    {
        $values->file('F');
        foreach (['D', 'B'] as $key) {
            $value = $values->typed($key, $key === 'D' ? [DictionaryType::class, IntegerType::class, StringType::class] : [DictionaryType::class, IntegerType::class], $key === 'D');
            if ($value instanceof IntegerType && $value->integer() < 0) {
                throw new \InvalidArgumentException('Thread indices must be nonnegative.');
            }
            if ($value instanceof DictionaryType) {
                $values->child($key, true, true);
                if ($values->entry('F') !== null) {
                    throw new \InvalidArgumentException('A Thread action with dictionary D/B refers to the current file and cannot specify F.');
                }
            }
        }
    }

    private function sound(ActionDictionaryValues $values): void
    {
        $values->typed('Sound', [StreamObject::class], true);
        $volume = $values->typed('Volume', [NumericType::class]);
        if ($volume instanceof NumericType && ($volume->value < -1 || $volume->value > 1)) {
            throw new \InvalidArgumentException('Sound action Volume must be in the range -1 to 1.');
        }
        foreach (['Synchronous', 'Repeat', 'Mix'] as $key) {
            $values->typed($key, [BooleanType::class]);
        }
    }

    private function movie(ActionDictionaryValues $values): void
    {
        if (($values->entry('Annotation') === null) === ($values->entry('T') === null)) {
            throw new \InvalidArgumentException('A Movie action requires exactly one of Annotation and T.');
        }
        if ($values->entry('Annotation') !== null) {
            $this->annotation($values, 'Annotation', ['Movie']);
        }
        $values->typed('T', [StringType::class]);
        $values->name('Operation', ['Play', 'Stop', 'Pause', 'Resume']);
    }

    private function hide(ActionDictionaryValues $values): void
    {
        $reference = $values->entry('T', true) ?? throw new \LogicException('Required T was checked.');
        $target = $this->graph->resolve($reference);
        foreach ($target instanceof ArrayType ? $target->items : [$reference] as $entry) {
            if ($this->graph->resolve($entry) instanceof StringType) {
                continue;
            }
            if (!$entry instanceof IndirectReference || $this->annotationIndex()->find($entry) === null) {
                throw new \InvalidArgumentException('Hide T contains field-name strings or indirect references to actual annotations.');
            }
        }
        $values->typed('H', [BooleanType::class]);
    }

    private function optionalContent(ActionDictionaryValues $values): void
    {
        $state = $values->typed('State', [ArrayType::class], true);
        if (!$state instanceof ArrayType) {
            throw new \LogicException('A required State array was checked.');
        }
        if (count($state) > $this->limits->maxNodes) {
            throw new \LengthException('SetOCGState exceeds the explicit action operand budget.');
        }
        $mode = null;
        $groups = 0;
        foreach ($state as $entry) {
            $value = $this->graph->resolve($entry);
            if ($value instanceof NameType) {
                if (!in_array($value->value, ['ON', 'OFF', 'Toggle'], true) || ($mode !== null && $groups === 0)) {
                    throw new \InvalidArgumentException('SetOCGState sequences require ON/OFF/Toggle followed by at least one group.');
                }
                $mode = $value->value;
                $groups = 0;
                continue;
            }
            $group = $this->graph->dictionary($entry);
            if ($mode === null || !$entry instanceof IndirectReference || !$group instanceof DictionaryType || $this->graph->resolve($entry) instanceof StreamObject) {
                throw new \InvalidArgumentException('SetOCGState group operands require indirect optional-content group dictionaries after a mode.');
            }
            (new ActionDictionaryValues($group, $this->graph))->name('Type', ['OCG'], true);
            $identity = $this->graph->identifier($entry);
            if ($identity === null || !isset($this->optionalGroups()[(string) $identity])) {
                throw new \InvalidArgumentException('A SetOCGState operand must belong to the document OCProperties.OCGs array.');
            }
            ++$groups;
        }
        if ($mode !== null && $groups === 0) {
            throw new \InvalidArgumentException('SetOCGState cannot end with a mode without its group.');
        }
        $values->typed('PreserveRB', [BooleanType::class]);
    }

    /** @return array<string,true> */
    private function optionalGroups(): array
    {
        if ($this->optionalGroups !== null) {
            return $this->optionalGroups;
        }
        $catalog = new ActionDictionaryValues($this->graph->catalog, $this->graph);
        $properties = $catalog->child('OCProperties', true) ?? throw new \LogicException('Required OCProperties was checked.');
        $registered = $properties->typed('OCGs', [ArrayType::class], true);
        if (!$registered instanceof ArrayType || count($registered) > $this->limits->maxNodes) {
            throw new \LengthException('The optional-content action index exceeds its explicit group budget.');
        }
        $groups = [];
        foreach ($registered as $reference) {
            $identity = $reference instanceof IndirectReference ? $this->graph->identifier($reference) : null;
            if ($identity === null) {
                throw new \InvalidArgumentException('Catalog OCProperties.OCGs must contain indirect group references.');
            }
            $groups[(string) $identity] = true;
        }
        return $this->optionalGroups = $groups;
    }

    private function rendition(ActionDictionaryValues $values): void
    {
        $script = $values->typed('JS', [StringType::class, StreamObject::class]);
        $operation = $values->typed('OP', [IntegerType::class], $script === null);
        if ($operation instanceof IntegerType && ($operation->integer() < 0 || $operation->integer() > 4) && $script === null) {
            throw new \InvalidArgumentException('An unknown Rendition OP requires its JS alternative.');
        }
        $knownOperation = $operation instanceof IntegerType && $operation->integer() >= 0 && $operation->integer() <= 4;
        $values->child('R', $knownOperation && in_array($operation->integer(), [0, 4], true));
        if ($knownOperation || $values->entry('AN') !== null) {
            $this->annotation($values, 'AN', ['Screen'], true);
        }
    }

    private function transition(ActionDictionaryValues $values): void
    {
        $transition = $values->child('Trans', true) ?? throw new \LogicException('Required Trans was checked.');
        (new TransitionDictionaryRules())->validate($transition->dictionary, $this->graph, $this->version);
    }

    private function documentPart(ActionDictionaryValues $values): void
    {
        $values->child('Dp', true, true);
        $selected = $values->entry('Dp', true) ?? throw new \LogicException('Required Dp was checked.');
        $catalog = new ActionDictionaryValues($this->graph->catalog, $this->graph);
        $root = $catalog->child('DPartRoot', true) ?? throw new \LogicException('Required DPartRoot was checked.');
        $pending = [$root->entry('DPartRootNode', true) ?? throw new \LogicException('Required DPartRootNode was checked.')];
        $seen = [];
        while ($pending !== []) {
            $reference = array_pop($pending);
            $identifier = $this->graph->identifier($reference);
            $key = $identifier === null ? 'direct:' . spl_object_id($reference) : (string) $identifier;
            if (isset($seen[$key])) {
                continue;
            }
            if (count($seen) >= $this->limits->maxNodes) {
                throw new \LengthException('Document-part action lookup exceeds its explicit graph budget.');
            }
            $seen[$key] = true;
            if ($this->graph->sameObject($selected, $reference)) {
                return;
            }
            $part = $this->graph->dictionary($reference);
            $children = $part === null ? null : (new ActionDictionaryValues($part, $this->graph))->typed('DParts', [ArrayType::class]);
            if ($children instanceof ArrayType) {
                array_push($pending, ...$children->items);
            }
        }
        throw new \InvalidArgumentException('GoToDp must target a document part in the actual catalog DPartRoot tree.');
    }

    private function threeD(ActionDictionaryValues $values): void
    {
        // EC3 #282 expands TA and the view source to RichMedia annotations.
        $this->annotation($values, 'TA', ['3D', 'RichMedia']);
        $view = $values->typed('V', [DictionaryType::class, IntegerType::class, StringType::class, NameType::class], true);
        if (($view instanceof IntegerType && $view->integer() < 0) || ($view instanceof NameType && !in_array($view->value, ['F', 'L', 'N', 'P', 'D'], true))) {
            throw new \InvalidArgumentException('GoTo3DView V requires a nonnegative view index or a standard F/L/N/P/D selector.');
        }
    }

    private function richMedia(ActionDictionaryValues $values): void
    {
        $annotation = $this->annotation($values, 'TA', ['RichMedia']);
        $instance = $values->child('TI', indirect: true);
        if ($instance !== null) {
            $content = $annotation->child('RichMediaContent', true) ?? throw new \LogicException('RichMedia content was checked.');
            $configurations = $content->typed('Configurations', [ArrayType::class], true);
            $selected = $values->entry('TI', true);
            $found = false;
            foreach ($configurations instanceof ArrayType ? $configurations->items : [] as $configuration) {
                $dictionary = $this->graph->dictionary($configuration);
                $instances = $dictionary === null ? null : (new ActionDictionaryValues($dictionary, $this->graph))->typed('Instances', [ArrayType::class]);
                foreach ($instances instanceof ArrayType ? $instances->items : [] as $candidate) {
                    $found = $found || ($selected !== null && $this->graph->sameObject($candidate, $selected));
                }
            }
            if (!$found) {
                throw new \InvalidArgumentException('RichMediaExecute TI must identify an instance of its target annotation.');
            }
        }
        $command = $values->child('CMD', true) ?? throw new \LogicException('Required CMD was checked.');
        $command->name('Type', ['RichMediaCommand']);
        $command->typed('C', [StringType::class], true);
        $arguments = $command->value('A');
        foreach ($arguments instanceof ArrayType ? $arguments->items : ($arguments === null ? [] : [$arguments]) as $argument) {
            $argument = $this->graph->resolve($argument);
            if (!$argument instanceof StringType && !$argument instanceof NumericType && !$argument instanceof BooleanType) {
                throw new \InvalidArgumentException('RichMedia command arguments are text strings, numbers or booleans.');
            }
        }
    }

    private function form(ActionDictionaryValues $values, bool $submit): void
    {
        if ($submit) {
            $values->file('F', true, true);
        }
        $fields = $values->typed('Fields', [ArrayType::class]);
        foreach ($fields instanceof ArrayType ? $fields->items : [] as $reference) {
            $field = $this->graph->resolve($reference);
            if ($field instanceof StringType) {
                $this->version?->require(PdfVersion::PDF13, 'Named form action field');
            } elseif (!$reference instanceof IndirectReference || !$field instanceof DictionaryType) {
                throw new \InvalidArgumentException('Form action Fields contains indirect field dictionaries or fully qualified field-name strings.');
            }
        }
        $flags = $values->typed('Flags', [IntegerType::class]);
        $bits = $flags instanceof IntegerType ? $flags->integer() : 0;
        $allowed = $submit ? 0x2fff : 1;
        if ($bits < 0 || ($bits & ~$allowed) !== 0) {
            throw new \InvalidArgumentException('Form action Flags has reserved or negative bits.');
        }
        if (!$submit) {
            return;
        }
        if (($bits & 0x2fe0) !== 0) {
            $this->version?->require(PdfVersion::PDF14, 'PDF1.4 submission flags');
        }
        if (($bits & 0x2000) !== 0) {
            $this->version?->require(PdfVersion::PDF15, 'EmbedForm submission flag');
        }
        if (($bits & 0x100) !== 0) {
            return; // SubmitPDF explicitly ignores every other flag except GetMethod.
        }
        if ((($bits & 0x18) !== 0 && ($bits & 4) === 0) || (($bits & 0x2cc0) !== 0 && ($bits & 0x24) !== 0)
            || (($bits & 0x400) !== 0 && ($bits & 0x80) === 0)) {
            throw new \InvalidArgumentException('SubmitForm Flags combines incompatible HTML, XFDF or FDF options.');
        }
    }

    /** @param non-empty-list<string> $subtypes */
    private function annotation(ActionDictionaryValues $values, string $key, array $subtypes, bool $pageRequired = false): ActionDictionaryValues
    {
        $annotation = $values->child($key, true, true) ?? throw new \LogicException('Required annotation was checked.');
        $annotation->name('Subtype', $subtypes, true);
        $reference = $values->entry($key, true) ?? throw new \LogicException('Required annotation was checked.');
        if ($this->annotationIndex()->find($reference) === null) {
            throw new \InvalidArgumentException('An action target annotation must belong to an actual page Annots array.');
        }
        if ($pageRequired) {
            $annotation->child('P', true, true);
        }
        return $annotation;
    }

    private function annotationIndex(): AnnotationPageIndex
    {
        return $this->annotations ??= new AnnotationPageIndex($this->graph, $this->limits);
    }
}
