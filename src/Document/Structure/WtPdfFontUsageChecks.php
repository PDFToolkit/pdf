<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Document\Fonts\CMaps\CMapCode;
use PDF\Document\Fonts\CMaps\CMapDecoder;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Fonts\Type0Font;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringType;

/** Checks actual typed text-showing operations, including q/Q, Tr, gs and nested Form XObjects. */
final readonly class WtPdfFontUsageChecks
{
    public function __construct(private int $maximumOperations = 1000000, private int $maximumDepth = 100, private int $maximumTextBytes = 1048576)
    {
        if ($maximumOperations < 1 || $maximumDepth < 1 || $maximumTextBytes < 1) {
            throw new \InvalidArgumentException('Font-usage inspection requires explicit positive budgets.');
        }
    }

    /** @return list<WtPdfFinding> */
    public function inspect(DictionaryType $catalog): array
    {
        $findings = [];
        $pages = $this->dictionary($catalog->get('Pages'));
        $queue = $pages === null ? [] : [[$pages, new DictionaryType()]];
        $seen = new \SplObjectStorage();
        $operations = 0;
        $textBytes = 0;
        $activeStreams = [];
        while ($queue !== []) {
            [$node, $inheritedResources] = array_pop($queue);
            if ($seen->offsetExists($node)) {
                throw new \InvalidArgumentException('Font-usage inspection requires a page tree without repeated nodes.');
            }
            $seen->offsetSet($node);
            $resources = $this->dictionary($node->get('Resources')) ?? $inheritedResources;
            $type = $node->get('Type');
            if ($type instanceof NameType && $type->value === 'Page') {
                $state = ['font' => null, 'mode' => 0];
                $stack = [];
                foreach ($this->streams($node->get('Contents')) as $stream) {
                    $this->stream($stream, $resources, $state, $stack, 0, $operations, $textBytes, $activeStreams, $findings);
                }
                if ($stack !== []) {
                    throw new \InvalidArgumentException('A page graphics-state save requires its matching restore.');
                }
            } else {
                $kids = $this->target($node->get('Kids'));
                foreach ($kids instanceof ArrayType ? $kids->items : [] as $kid) {
                    $dictionary = $this->dictionary($kid);
                    if ($dictionary !== null) {
                        $queue[] = [$dictionary, $resources];
                    }
                }
            }
            if (count($seen) > $this->maximumOperations) {
                throw new \OverflowException('Font-usage page traversal exceeds its explicit budget.');
            }
        }
        return $findings;
    }

    /**
     * @param array{font: ?DictionaryType, mode: int} $state
     * @param list<array{font: ?DictionaryType, mode: int}> $stack
     * @param list<WtPdfFinding> $findings
     * @param array<int, true> $activeStreams
     */
    private function stream(StreamObject $stream, DictionaryType $resources, array &$state, array &$stack, int $depth, int &$operations, int &$textBytes, array &$activeStreams, array &$findings): void
    {
        $identity = spl_object_id($stream);
        if (isset($activeStreams[$identity])) {
            throw new \InvalidArgumentException('Form XObject content-stream recursion contains a cycle.');
        }
        if ($depth >= $this->maximumDepth) {
            throw new \OverflowException('Font-usage Form recursion exceeds its explicit depth budget.');
        }
        if (!$stream->data instanceof ContentStreams) {
            $findings[] = new WtPdfFinding('8.4.5', 32, WtPdfFindingStatus::AdditionalValidationRequired, 'Read opaque content bytes with the optional reader before auditing font usage.');
            return;
        }
        $activeStreams[$identity] = true;
        try {
            foreach ($stream->data->operators as $operator) {
                if (++$operations > $this->maximumOperations) {
                    throw new \OverflowException('Font-usage inspection exceeds its explicit operation budget.');
                }
                $symbol = $operator->symbol();
                $operands = $operator->operands;
                if ($symbol === 'q') {
                    $stack[] = $state;
                } elseif ($symbol === 'Q') {
                    $state = array_pop($stack) ?? throw new \InvalidArgumentException('A graphics-state restoration requires its matching save.');
                } elseif ($symbol === 'Tf') {
                    $fonts = $this->dictionary($resources->get('Font'));
                    $name = $operands[0] ?? null;
                    $state['font'] = $name instanceof NameType ? $this->dictionary($fonts?->get($name)) : null;
                } elseif ($symbol === 'Tr') {
                    $mode = $operands[0] ?? null;
                    if ($mode instanceof \PDF\Syntax\Object\NumericType) {
                        $state['mode'] = (int) $mode->value;
                    }
                } elseif ($symbol === 'gs') {
                    $states = $this->dictionary($resources->get('ExtGState'));
                    $name = $operands[0] ?? null;
                    $parameters = $name instanceof NameType ? $this->dictionary($states?->get($name)) : null;
                    $font = $this->target($parameters?->get('Font'));
                    if ($font instanceof ArrayType) {
                        $state['font'] = $this->dictionary($font->items[0] ?? null);
                    }
                } elseif ($symbol === 'Do') {
                    $objects = $this->dictionary($resources->get('XObject'));
                    $name = $operands[0] ?? null;
                    $form = $name instanceof NameType ? $this->target($objects?->get($name)) : null;
                    $subtype = $form instanceof StreamObject ? $form->dictionary()->get('Subtype') : null;
                    if ($form instanceof StreamObject && $subtype instanceof NameType && $subtype->value === 'Form') {
                        $formState = $state;
                        $formStack = [];
                        $this->stream($form, $this->dictionary($form->dictionary()->get('Resources')) ?? $resources, $formState, $formStack, $depth + 1, $operations, $textBytes, $activeStreams, $findings);
                        if ($formStack !== []) {
                            throw new \InvalidArgumentException('A Form graphics-state save requires its matching restore.');
                        }
                    }
                } elseif (in_array($symbol, ['Tj', 'TJ', "'", '"'], true)) {
                    $strings = [];
                    foreach ($operands as $operand) {
                        if ($operand instanceof StringType) {
                            $strings[] = $operand;
                        } elseif ($operand instanceof ArrayType) {
                            foreach ($operand->items as $item) {
                                if ($item instanceof StringType) {
                                    $strings[] = $item;
                                }
                            }
                        }
                    }
                    foreach ($strings as $string) {
                        if ($string->value !== '') {
                            $length = strlen($string->value);
                            if ($length > $this->maximumTextBytes - $textBytes) {
                                throw new \OverflowException('Font-usage text inspection exceeds its explicit cumulative byte budget.');
                            }
                            $textBytes += $length;
                            $this->text($state['font'], $string, $state['mode'] !== 3, $findings);
                        }
                    }
                }
            }
        } finally {
            unset($activeStreams[$identity]);
        }
    }

    /** @param list<WtPdfFinding> $findings */
    private function text(?DictionaryType $font, StringType $text, bool $rendered, array &$findings): void
    {
        $fail = static function (string $clause, int $page, string $message) use (&$findings): void {
            $findings[] = new WtPdfFinding($clause, $page, WtPdfFindingStatus::Failed, $message);
        };
        if ($font === null) {
            $fail('8.4.5.2', 32, 'Every text-showing operation requires a resolved current font.');
            return;
        }
        $subtype = $font->get('Subtype');
        $composite = $subtype instanceof NameType && $subtype->value === 'Type0';
        $physical = $font;
        if ($composite) {
            $descendants = $this->target($font->get('DescendantFonts'));
            $physical = $descendants instanceof ArrayType ? ($this->dictionary($descendants->items[0] ?? null) ?? $font) : $font;
        }
        $descriptor = $this->dictionary($physical->get('FontDescriptor'));
        $physicalSubtype = $physical->get('Subtype');
        $embedded = $physicalSubtype instanceof NameType && $physicalSubtype->value === 'Type3';
        foreach (['FontFile', 'FontFile2', 'FontFile3'] as $key) {
            $embedded = $embedded || $this->target($descriptor?->get($key)) instanceof StreamObject;
        }
        if ($rendered && !$embedded) {
            $fail('8.4.5.5.1', 33, 'A font whose glyphs are rendered must have its font program embedded.');
        }
        $encoding = $this->target($font->get('Encoding'));
        $base = $encoding instanceof DictionaryType ? $encoding->get('BaseEncoding') : $encoding;
        $flags = $descriptor?->get('Flags');
        $nonsymbolic = $flags instanceof IntegerType && ($flags->integer() & 32) !== 0;
        $exempt = $base instanceof NameType && in_array($base->value, ['MacRomanEncoding', 'MacExpertEncoding', 'WinAnsiEncoding'], true);
        $exempt = $exempt || ($physicalSubtype instanceof NameType && $physicalSubtype->value === 'TrueType' && $nonsymbolic);
        $info = $this->dictionary($physical->get('CIDSystemInfo'));
        $registry = $info?->get('Registry');
        $ordering = $info?->get('Ordering');
        $supplement = $info?->get('Supplement');
        $collectionExempt = $ordering instanceof StringType && (in_array($ordering->value, ['GB1', 'CNS1', 'Japan1'], true) || ($ordering->value === 'KR' && $supplement instanceof IntegerType && $supplement->integer() >= 9));
        $exempt = $exempt || ($composite && $registry instanceof StringType && $registry->value === 'Adobe' && $collectionExempt);
        $unicode = $this->target($font->get('ToUnicode'));
        if (!$exempt && !$unicode instanceof StreamObject) {
            if ($subtype instanceof NameType && in_array($subtype->value, ['Type1', 'MMType1', 'Type3'], true)) {
                $findings[] = new WtPdfFinding('8.4.5.8', 35, WtPdfFindingStatus::AdditionalValidationRequired, 'A ToUnicode stream is required unless all used glyph names are covered by the standard Adobe/Symbol glyph-name exemption.');
            } else {
                $fail('8.4.5.8', 35, 'This font requires a ToUnicode stream mapping every referenced glyph.');
            }
        }
        if ($physicalSubtype instanceof NameType && $physicalSubtype->value === 'TrueType') {
            if ($nonsymbolic && !($base instanceof NameType && in_array($base->value, ['MacRomanEncoding', 'WinAnsiEncoding'], true))) {
                $fail('8.4.5.7', 34, 'A nonsymbolic TrueType font requires MacRomanEncoding or WinAnsiEncoding.');
            }
            if ($flags instanceof IntegerType && ($flags->integer() & 4) !== 0 && $font->has('Encoding')) {
                $fail('8.4.5.7', 34, 'A symbolic TrueType font must omit Encoding.');
            }
        }
        $codes = [];
        if ($font instanceof Type0Font) {
            foreach ((new CMapDecoder($font->encoding->codeSpaces()))->decode(new HexadecimalString($text->value)) as $decoded) {
                $codes[] = $decoded->code;
                try {
                    $cid = $decoded->defined ? $font->encoding->cid($decoded->code) : null;
                    if ($cid === null || $cid->integer() === 0) {
                        $fail('8.4.5.9', 35, 'A text-showing operation references an undefined character or .notdef CID.');
                        continue;
                    }
                    $map = $this->target($physical->get('CIDToGIDMap'));
                    if ($map instanceof \PDF\Document\Fonts\FontStream && is_string($map->data) && (strlen($map->data) < $cid->integer() * 2 + 2 || substr($map->data, $cid->integer() * 2, 2) === "\0\0")) {
                        $fail('8.4.5.9', 35, 'A text-showing operation resolves to .notdef glyph index zero.');
                    }
                } catch (\LogicException) {
                    $findings[] = new WtPdfFinding('8.4.5.9', 35, WtPdfFindingStatus::AdditionalValidationRequired, 'Resolve standard external CMap data before checking the used glyph indices.');
                }
            }
        } elseif (!$composite) {
            foreach (str_split($text->value) as $byte) {
                $codes[] = new CMapCode(new HexadecimalString($byte));
            }
        }
        if ($unicode instanceof ToUnicodeCMap) {
            foreach ($codes as $code) {
                if (!$unicode->codeSpaces()->contains($code) || $unicode->sequence($code) === null) {
                    $fail('8.4.5.8', 35, 'The ToUnicode CMap must map every referenced glyph character code.');
                }
            }
        }
    }

    /** @return list<StreamObject> */
    private function streams(?BasicType $value): array
    {
        $value = $this->target($value);
        if ($value instanceof StreamObject) {
            return [$value];
        }
        $streams = [];
        foreach ($value instanceof ArrayType ? $value->items : [] as $reference) {
            $stream = $this->target($reference);
            if ($stream instanceof StreamObject) {
                $streams[] = $stream;
            }
        }
        return $streams;
    }

    private function target(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }

    private function dictionary(?BasicType $value): ?DictionaryType
    {
        $value = $this->target($value);
        return $value instanceof DictionaryType ? $value : null;
    }
}
