<?php

declare(strict_types=1);

namespace PDF\Document\Fonts;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Specification\Definition\Type0FontDictionaryTable121Definition;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\StringType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StreamObject;
use PDF\Document\Fonts\CMaps\Type0Encoding;
use PDF\Document\Fonts\CMaps\IdentityEncoding;

/** Table 121: explicit identity, named or embedded CMap encoding; optional ToUnicode extraction map. */
final class Type0Font extends ManagedFontDictionary implements VersionedComponent
{
    public const string TABLE = '121';

    public readonly Type0Encoding $encoding;

    public function __construct(public readonly DictionaryType $descendantFont, ?ToUnicodeCMap $toUnicode = null, Entry|Type0Encoding ...$entries)
    {
        $encoding = null;
        $additional = [];
        foreach ($entries as $entry) {
            if ($entry instanceof Type0Encoding) {
                if ($encoding !== null) {
                    throw new \InvalidArgumentException('A Type 0 font selects exactly one encoding.');
                }
                $encoding = $entry;
            } else {
                $additional[] = $entry;
            }
        }
        $this->encoding = $encoding ?? new IdentityEncoding();
        $baseFont = $descendantFont->get('BaseFont');
        $subtype = $descendantFont->get('Subtype');
        $type = $descendantFont->get('Type');
        if (!$type instanceof NameType || $type->value !== 'Font' || !$baseFont instanceof NameType || !$subtype instanceof NameType || !in_array($subtype->value, ['CIDFontType0', 'CIDFontType2'], true)) {
            throw new \InvalidArgumentException('A Type 0 descendant is a CIDFontType0/2 dictionary with a BaseFont name.');
        }
        $descriptor = $descendantFont->get('FontDescriptor');
        if (!$descriptor instanceof IndirectReference) {
            throw new \InvalidArgumentException('A CIDFont FontDescriptor is an indirect dictionary.');
        }
        $descriptor = $descriptor->target();
        $descriptorName = $descriptor instanceof DictionaryType ? $descriptor->get('FontName') : null;
        if (!$descriptor instanceof DictionaryType || !$descriptorName instanceof NameType || $descriptorName->value !== $baseFont->value) {
            throw new \InvalidArgumentException('A CIDFont has a descriptor with a matching FontName.');
        }
        if ($descriptor instanceof FontDescriptor) {
            $descriptor->assertFor($subtype->value === 'CIDFontType0' ? FontSubtype::CIDFontType0 : FontSubtype::CIDFontType2);
        }
        $embedded = false;
        $programCount = 0;
        foreach (['FontFile', 'FontFile2', 'FontFile3'] as $key) {
            $program = $descriptor->get($key);
            if ($program instanceof IndirectReference && $program->target() instanceof StreamObject) {
                $embedded = true;
                ++$programCount;
                $programSubtype = $program->target()->dictionary()->get('Subtype');
                if ($key === 'FontFile' || ($key === 'FontFile2' && $subtype->value !== 'CIDFontType2') || ($key === 'FontFile3' && (!$programSubtype instanceof NameType || !in_array($programSubtype->value, $subtype->value === 'CIDFontType0' ? ['CIDFontType0C', 'OpenType'] : ['OpenType'], true)))) {
                    throw new \InvalidArgumentException('The embedded program key/subtype matches the CID font family.');
                }
            } elseif ($program !== null) {
                throw new \InvalidArgumentException('An embedded font program is an indirect stream.');
            }
        }
        if ($programCount > 1 || ($subtype->value === 'CIDFontType0' && $descendantFont->has('CIDToGIDMap')) || (!$embedded && $descendantFont->has('CIDToGIDMap'))) {
            throw new \InvalidArgumentException('One embedded program is selected; only embedded Type 2 CID fonts have CIDToGIDMap.');
        }
        if ((!$embedded && $this->encoding instanceof IdentityEncoding) || (!$embedded && $subtype->value === 'CIDFontType2' && !$this->encoding->object() instanceof NameType)) {
            throw new \InvalidArgumentException('Identity CMaps require an embedded font; nonembedded CIDFontType2 fonts require a predefined named CMap.');
        }
        $collection = $this->encoding->collection();
        if ($collection !== null) {
            $info = $descendantFont->get('CIDSystemInfo');
            $info = $info instanceof IndirectReference ? $info->target() : $info;
            $registry = $info instanceof DictionaryType ? $info->get('Registry') : null;
            $ordering = $info instanceof DictionaryType ? $info->get('Ordering') : null;
            $supplement = $info instanceof DictionaryType ? $info->get('Supplement') : null;
            if (!$registry instanceof StringType || $registry->value !== $collection->registry->value || !$ordering instanceof StringType || $ordering->value !== $collection->ordering->value || !$supplement instanceof IntegerType || $supplement->integer() < 0) {
                throw new \InvalidArgumentException('Font and encoding share Registry/Ordering; Supplement is a nonnegative collection revision, not a compatibility discriminator.');
            }
        }
        if ($toUnicode?->map instanceof CharacterMap && (!$descendantFont instanceof CIDFontType2 || $descendantFont->map !== $toUnicode->map || !$this->encoding instanceof IdentityEncoding)) {
            throw new \InvalidArgumentException('A repertoire-derived ToUnicode map uses the same CIDFontType2 repertoire and identity encoding.');
        }
        foreach ($toUnicode?->codeSpaces()->ranges ?? [] as $range) {
            if (!$this->encoding->codeSpaces()->coversSpace($range)) {
                throw new \InvalidArgumentException('ToUnicode codespaces must be consistent with the font character-code encoding.');
            }
        }
        $managed = ['Type', 'Subtype', 'BaseFont', 'Encoding', 'DescendantFonts', 'ToUnicode'];
        foreach ($additional as $entry) {
            if (in_array($entry->key()->value, $managed, true)) {
                throw new \InvalidArgumentException('The Type 0 font manages /' . $entry->key()->value . '.');
            }
        }
        $required = [
            new Entry(new NameType('Type'), new NameType('Font')),
            new Entry(new NameType('Subtype'), new NameType('Type0')),
            new Entry(new NameType('BaseFont'), $baseFont),
            new Entry(new NameType('Encoding'), $this->encoding->object()),
            new Entry(new NameType('DescendantFonts'), new ArrayType(new IndirectReference($descendantFont))),
        ];
        if ($toUnicode !== null) {
            $required[] = new Entry(new NameType('ToUnicode'), new IndirectReference($toUnicode));
        }
        parent::__construct(
            new Type0FontDictionaryTable121Definition(),
            $managed,
            ...[...$required, ...$additional],
        );
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF12, 'Type 0 composite font');
        if ($this->encoding instanceof VersionedComponent) {
            $this->encoding->validateVersion($version);
        }
    }
}
