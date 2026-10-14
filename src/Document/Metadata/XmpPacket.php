<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** An explicit UTF-8 RDF/XMP packet. No XML input, DTD, network fetch or inferred claim. */
final readonly class XmpPacket
{
    /** @var list<XmpProperty> */
    public array $properties;
    public string $xml;

    public function __construct(XmpProperty ...$properties)
    {
        $this->properties = array_values($properties);
        $namespaces = [];
        $names = [];
        $body = '';
        foreach ($properties as $property) {
            if (isset($names[$property->expandedName()])) {
                throw new \InvalidArgumentException('A packet cannot repeat an expanded XMP property name.');
            }
            $names[$property->expandedName()] = true;
            foreach ($property->namespaces() as $prefix => $namespace) {
                new XmpName($prefix, new MetadataUri($namespace), 'property');
                if (isset($namespaces[$prefix]) && $namespaces[$prefix] !== $namespace) {
                    throw new \InvalidArgumentException('An XMP namespace prefix cannot identify two namespaces.');
                }
                $namespaces[$prefix] = $namespace;
            }
            $body .= $property->toXml();
        }
        $bindings = '';
        foreach ($namespaces as $prefix => $namespace) {
            $bindings .= ' xmlns:' . $prefix . '="' . (new XmlText($namespace))->escaped() . '"';
        }
        $this->xml = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about=""' . $bindings . '>' . $body . '</rdf:Description></rdf:RDF></x:xmpmeta>'
            . "\n" . '<?xpacket end="w"?>';
    }
}
