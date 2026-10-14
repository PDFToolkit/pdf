<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Syntax\DataStructure\XmlName;

/** A namespace-qualified XMP property name with an explicit URI. */
final readonly class XmpName
{
    public function __construct(public string $prefix, public MetadataUri $namespace, public string $localName)
    {
        foreach ([$prefix, $localName] as $part) {
            new XmlName($part);
            if (str_contains($part, ':')) {
                throw new \InvalidArgumentException('XMP prefix and local name require XML NCNames without colons.');
            }
        }
        if (in_array(strtolower($prefix), ['xml', 'xmlns', 'rdf', 'x'], true)) {
            throw new \InvalidArgumentException('The XML, RDF and XMP wrapper prefixes are reserved.');
        }
    }

    public function qualified(): string
    {
        return $this->prefix . ':' . $this->localName;
    }
    public function expanded(): string
    {
        return '{' . $this->namespace->value . '}' . $this->localName;
    }
}
