<?php

declare(strict_types=1);

namespace PDF\Document\Metadata\Declarations;

use PDF\Document\Metadata\XmlText;
use PDF\Document\Metadata\XmpProperty;

/** PDF-Declarations.pdf p.10/§9: explicit PDF/A extension schema, without a PDF/A conformance claim. */
final readonly class DeclarationsPdfaExtensionSchema implements XmpProperty
{
    public function namespaces(): array
    {
        return ['pdfaExtension' => 'http://www.aiim.org/pdfa/ns/extension/', 'pdfaSchema' => 'http://www.aiim.org/pdfa/ns/schema#', 'pdfaProperty' => 'http://www.aiim.org/pdfa/ns/property#', 'pdfaType' => 'http://www.aiim.org/pdfa/ns/type#', 'pdfaField' => 'http://www.aiim.org/pdfa/ns/field#'];
    }

    public function expandedName(): string
    {
        return '{http://www.aiim.org/pdfa/ns/extension/}schemas';
    }

    public function toXml(): string
    {
        $xml = '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            . '<pdfaSchema:schema>PDF Declarations Schema</pdfaSchema:schema><pdfaSchema:namespaceURI>' . Declarations::NAMESPACE . '</pdfaSchema:namespaceURI><pdfaSchema:prefix>pdfd</pdfaSchema:prefix>'
            . '<pdfaSchema:property><rdf:Seq><rdf:li rdf:parseType="Resource"><pdfaProperty:name>declarations</pdfaProperty:name><pdfaProperty:valueType>Bag Declaration</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>Conformance assertions supplied by the document author.</pdfaProperty:description></rdf:li></rdf:Seq></pdfaSchema:property><pdfaSchema:valueType><rdf:Seq>';
        $types = ['Declaration' => ['conformsTo' => 'URI', 'claimData' => 'Bag ClaimData'], 'ClaimData' => ['claimBy' => 'Text', 'claimDate' => 'Date', 'claimCredentials' => 'Text', 'claimReport' => 'URL']];
        foreach ($types as $type => $fields) {
            $xml .= '<rdf:li rdf:parseType="Resource"><pdfaType:type>' . $type . '</pdfaType:type><pdfaType:namespaceURI>' . Declarations::NAMESPACE . '</pdfaType:namespaceURI><pdfaType:prefix>pdfd</pdfaType:prefix><pdfaType:description>' . $type . ' structure.</pdfaType:description><pdfaType:field><rdf:Seq>';
            foreach ($fields as $name => $valueType) {
                $xml .= '<rdf:li rdf:parseType="Resource"><pdfaField:name>' . $name . '</pdfaField:name><pdfaField:valueType>' . $valueType . '</pdfaField:valueType><pdfaField:description>' . (new XmlText('PDF Declarations field ' . $name . '.'))->escaped() . '</pdfaField:description></rdf:li>';
            }
            $xml .= '</rdf:Seq></pdfaType:field></rdf:li>';
        }
        return $xml . '</rdf:Seq></pdfaSchema:valueType></rdf:li></rdf:Bag></pdfaExtension:schemas>';
    }
}
