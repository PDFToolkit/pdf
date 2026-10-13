<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Document\Fonts\Encodings\FontEncoding;
use PDF\Document\Fonts\Encodings\PredefinedEncoding;
use PDF\Document\Fonts\Encodings\FontEncodingValidation;
use PDF\Specification\Definition\Type1FontDictionaryTable111Definition;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** Shared Table 111 construction for Type1, MMType1 and TrueType. */
abstract class SimpleFont extends ManagedFontDictionary implements VersionedComponent
{
    protected function __construct(FontSubtype $subtype, NameType $baseFont, Entry|SimpleFontMetrics|FontEncoding|ToUnicodeCMap ...$values)
    {
        $entries = [];
        $metrics = null;
        foreach ($values as $value) {
            if ($value instanceof SimpleFontMetrics) {
                if ($metrics !== null) {
                    throw new \InvalidArgumentException('A simple font has one metric graph.');
                }
                $metrics = $value;
                $entries[] = new Entry(new NameType('FirstChar'), $value->widths->first);
                $entries[] = new Entry(new NameType('LastChar'), $value->widths->last);
                $entries[] = new Entry(new NameType('Widths'), new IndirectReference($value->widths));
                $entries[] = new Entry(new NameType('FontDescriptor'), new IndirectReference($value->descriptor));
            } elseif ($value instanceof FontEncoding) {
                $entries[] = new Entry(new NameType('Encoding'), $value->object());
            } elseif ($value instanceof ToUnicodeCMap) {
                foreach ($value->codeSpaces()->ranges as $space) {
                    if ($space->low->length() !== 1) {
                        throw new \InvalidArgumentException('A simple-font ToUnicode map uses one-byte character codes.');
                    }
                }
                $entries[] = new Entry(new NameType('ToUnicode'), new IndirectReference($value));
            } else {
                if (in_array($value->key()->value, ['Type', 'Subtype', 'BaseFont'], true)) {
                    throw new \InvalidArgumentException('The simple font supplies Type, Subtype and BaseFont.');
                }
                $entries[] = $value;
            }
        }
        $graph = new DictionaryType(...$entries);
        $count = 0;
        foreach (['FirstChar', 'LastChar', 'Widths', 'FontDescriptor'] as $key) {
            $count += $graph->has($key) ? 1 : 0;
        }
        $standard = $subtype === FontSubtype::Type1 && StandardFont::tryFrom($baseFont->value) !== null;
        if ((!$standard && $count !== 4) || ($count !== 0 && $count !== 4)) {
            throw new \InvalidArgumentException('Simple fonts have complete metrics; the standard 14 may omit all four metric entries together.');
        }
        if ($count === 4) {
            $first = $graph->get('FirstChar');
            $last = $graph->get('LastChar');
            $widths = $graph->get('Widths');
            $widths = $widths instanceof IndirectReference ? $widths->target() : $widths;
            $descriptor = $graph->get('FontDescriptor');
            if (!$first instanceof IntegerType || !$last instanceof IntegerType || $first->integer() < 0 || $last->integer() > 255 || $first->integer() > $last->integer() || !$widths instanceof ArrayType || count($widths) !== $last->integer() - $first->integer() + 1 || !$descriptor instanceof IndirectReference || !$descriptor->target() instanceof FontDescriptor) {
                throw new \InvalidArgumentException('FirstChar/LastChar, Widths cardinality and indirect descriptor must agree.');
            }
            foreach ($widths->items as $width) {
                if (!$width instanceof NumericType) {
                    throw new \InvalidArgumentException('Each simple-font width is a number.');
                }
            }
            $fontDescriptor = $descriptor->target();
            if ($fontDescriptor->fontName->value !== $baseFont->value) {
                throw new \InvalidArgumentException('Simple BaseFont and FontDescriptor FontName agree.');
            }
            $fontDescriptor->assertFor($subtype);
        }
        $encoding = $graph->get('Encoding');
        $encoding = $encoding instanceof IndirectReference ? $encoding->target() : $encoding;
        if ($encoding !== null) {
            if (!$encoding instanceof NameType && !$encoding instanceof DictionaryType) {
                throw new \InvalidArgumentException('A simple-font Encoding is a predefined name or an encoding dictionary.');
            }
            (new FontEncodingValidation())->validate($encoding);
        }
        $unicode = $graph->get('ToUnicode');
        if ($unicode !== null) {
            if (!$unicode instanceof IndirectReference || !$unicode->target() instanceof ToUnicodeCMap) {
                throw new \InvalidArgumentException('ToUnicode is an indirect, explicitly constructed Unicode CMap.');
            }
            foreach ($unicode->target()->codeSpaces()->ranges as $space) {
                if ($space->low->length() !== 1) {
                    throw new \InvalidArgumentException('A simple-font ToUnicode map uses byte codes.');
                }
            }
        }
        parent::__construct(
            new Type1FontDictionaryTable111Definition(),
            ['Type', 'Subtype', 'BaseFont', 'FirstChar', 'LastChar', 'Widths', 'FontDescriptor', 'Encoding', 'ToUnicode'],
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType($subtype->value)),
            new Entry(new NameType('BaseFont'), $baseFont),
            ...$entries
        );
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        if ($version->value === PdfVersion::PDF10 && !$this->has('Name')) {
            throw new \LogicException('PDF 1.0 simple-font dictionaries require the resource Name.');
        }
    }
}
