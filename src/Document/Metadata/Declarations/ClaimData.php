<?php

declare(strict_types=1);

namespace PDF\Document\Metadata\Declarations;

use PDF\Document\Metadata\XmlText;

/** PDF-Declarations.pdf pp.9–10/Table3: every claim field is optional. */
final readonly class ClaimData
{
    public function __construct(public ?XmlText $claimBy = null, public ?ClaimDate $claimDate = null, public ?XmlText $claimCredentials = null, public ?ClaimReport $claimReport = null)
    {
    }
    public function toXml(): string
    {
        $xml = '<rdf:li rdf:parseType="Resource">';
        foreach (['claimBy' => $this->claimBy?->value, 'claimDate' => $this->claimDate?->value, 'claimCredentials' => $this->claimCredentials?->value, 'claimReport' => $this->claimReport?->value] as $name => $value) {
            if ($value !== null) {
                $xml .= '<pdfd:' . $name . '>' . (new XmlText($value))->escaped() . '</pdfd:' . $name . '>';
            }
        }
        return $xml . '</rdf:li>';
    }
}
