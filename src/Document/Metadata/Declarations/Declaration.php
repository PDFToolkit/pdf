<?php

declare(strict_types=1);

namespace PDF\Document\Metadata\Declarations;

use PDF\Document\Metadata\MetadataUri;
use PDF\Document\Metadata\XmlText;

/** PDF-Declarations.pdf p.9/Table2: a caller's assertion, never a certification. */
final readonly class Declaration
{
    /** @var list<ClaimData> */
    public array $claimData;
    public function __construct(public MetadataUri $conformsTo, ClaimData ...$claimData)
    {
        $this->claimData = array_values($claimData);
    }
    public function toXml(): string
    {
        $xml = '<rdf:li rdf:parseType="Resource"><pdfd:conformsTo>' . (new XmlText($this->conformsTo->value))->escaped() . '</pdfd:conformsTo>';
        if ($this->claimData !== []) {
            $xml .= '<pdfd:claimData><rdf:Bag>' . implode('', array_map(static fn (ClaimData $claim): string => $claim->toXml(), $this->claimData)) . '</rdf:Bag></pdfd:claimData>';
        }
        return $xml . '</rdf:li>';
    }
}
