<?php

declare(strict_types=1);

namespace PDF\Document\Fonts\CMaps;

use PDF\Document\VersionedComponent;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Reference a reader's predefined resource; its declared collection/codespaces stay explicit. */
final readonly class NamedCMapEncoding implements Type0Encoding, VersionedComponent
{
    public function __construct(private PredefinedCMap $resource, private CMapSystemInfo $systemInfo, private CodeSpaces $spaces, private ?CidMappings $resolvedMappings = null)
    {
        if ($resource->ordering() === null || $spaces->ranges === []) {
            throw new \InvalidArgumentException('Use IdentityEncoding for Identity-H/V; other named resources declare nonempty codespaces and collection metadata.');
        }
        if ($systemInfo->registry->value !== 'Adobe' || $systemInfo->ordering->value !== $resource->ordering()) {
            throw new \InvalidArgumentException('A predefined CMap declares its standard Adobe CID collection ordering.');
        }
        $resolvedMappings?->validate($spaces);
    }
    public function object(): NameType
    {
        return $this->resource->name()->value;
    }
    public function name(): CMapName
    {
        return $this->resource->name();
    }
    public function mode(): CMapWritingMode
    {
        return $this->resource->mode();
    }
    public function codeSpaces(): CodeSpaces
    {
        return $this->spaces;
    }
    public function collection(): CMapSystemInfo
    {
        return $this->systemInfo;
    }
    public function cid(CMapCode $code, bool $notdef = false): ?IntegerType
    {
        if ($this->resolvedMappings === null) {
            throw new \LogicException('Named CMap code resolution requires explicitly supplied resource mappings; no external resource is loaded.');
        }
        return $this->resolvedMappings->cid($code, $notdef);
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require($this->resource->minimumVersion(), 'Predefined CMap ' . $this->resource->value);
    }
}
