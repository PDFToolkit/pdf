<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Document\Fonts\FontStream;
use PDF\Specification\Dictionary\CMapStreamDictionaryTable120;
use PDF\Syntax\Filters\Filter;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Embedded Type 0 encoding CMap, ISO 9.7.5.3-4, PDF pp. 285-289. */
final class EncodingCMap extends FontStream implements Type0Encoding
{
    private readonly CodeSpaces $effectiveSpaces;

    public function __construct(private readonly CMapName $resourceName, private readonly CMapSystemInfo $systemInfo, CodeSpaces $spaces, public readonly CidMappings $mappings, private readonly CMapWritingMode $writingMode = CMapWritingMode::Horizontal, public readonly ?Type0Encoding $base = null, Filter ...$filters)
    {
        $this->effectiveSpaces = new CodeSpaces(...[...($base?->codeSpaces()->ranges ?? []), ...$spaces->ranges]);
        if ($this->effectiveSpaces->ranges === [] || ($base !== null && $base->name()->value->value === $resourceName->value->value)) {
            throw new \InvalidArgumentException('An encoding CMap has a nonempty effective codespace and a distinct base resource name.');
        }
        $baseInfo = $base?->collection();
        if ($baseInfo !== null && ($baseInfo->registry->value !== $systemInfo->registry->value || $baseInfo->ordering->value !== $systemInfo->ordering->value)) {
            throw new \InvalidArgumentException('A derived encoding CMap retains its base CID collection registry/ordering.');
        }
        $mappings->validate($this->effectiveSpaces);
        $entries = [new Entry(new NameType('Type'), new NameType('CMap')), new Entry(new NameType('CMapName'), $resourceName->value), new Entry(new NameType('CIDSystemInfo'), $systemInfo->dictionary()), new Entry(new NameType('WMode'), new IntegerType($writingMode->value))];
        if ($base !== null) {
            $entries[] = new Entry(new NameType('UseCMap'), $base->object());
        }
        // Stream bases are identified by the PDF dictionary. Only predefined name
        // bases are also resolvable as a PostScript usecmap resource instruction.
        $programBase = $base !== null && $base->object() instanceof NameType ? $base->name() : null;
        parent::__construct((new CMapProgram($resourceName, $systemInfo, $spaces, $mappings, $writingMode, $programBase))->bytes(), new CMapStreamDictionaryTable120(...$entries), ...$filters);
    }
    public function object(): IndirectReference
    {
        return new IndirectReference($this);
    }
    public function name(): CMapName
    {
        return $this->resourceName;
    }
    public function mode(): CMapWritingMode
    {
        return $this->writingMode;
    }
    public function codeSpaces(): CodeSpaces
    {
        return $this->effectiveSpaces;
    }
    public function collection(): CMapSystemInfo
    {
        return $this->systemInfo;
    }
    public function cid(CMapCode $code, bool $notdef = false): ?IntegerType
    {
        if (!$this->effectiveSpaces->contains($code)) {
            throw new \InvalidArgumentException('A character code must belong to the CMap codespace.');
        }
        $local = $this->mappings->cid($code, $notdef);
        return $local ?? ($this->base !== null && $this->base->codeSpaces()->contains($code) ? $this->base->cid($code, $notdef) : null);
    }

    /** ISO 9.7.6.3: ordinary mapping, then notdef if absent or without a glyph, then CID zero. */
    public function resolve(CMapCode|DecodedCMapCode $code, CidGlyphs $glyphs): IntegerType
    {
        if ($code instanceof DecodedCMapCode) {
            if (!$code->defined) {
                return new IntegerType(0);
            }
            $code = $code->code;
        }
        $normal = $this->cid($code);
        if ($normal !== null && $glyphs->contains($normal)) {
            return $normal;
        }
        $fallback = $this->cid($code, true);
        return $fallback !== null && $glyphs->contains($fallback) ? $fallback : new IntegerType(0);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        parent::validateVersion($version);
        $version->require(PdfVersion::PDF12, 'Encoding CMap');
        if ($this->base instanceof VersionedComponent) {
            $this->base->validateVersion($version);
        }
    }
}
