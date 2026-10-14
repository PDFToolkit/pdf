<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** An XMP Lang Alt property; its explicit x-default value precedes all language choices. */
final readonly class XmpLanguageAlternative implements XmpProperty
{
    /** @var non-empty-list<XmpLocalizedText> */
    public array $values;

    public function __construct(public XmpName $name, XmlText $default, XmpLocalizedText ...$alternatives)
    {
        $seen = ['x-default' => true];
        foreach ($alternatives as $alternative) {
            $key = strtolower($alternative->language);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('An alternate-text property cannot repeat a language.');
            }
            $seen[$key] = true;
        }
        $this->values = [new XmpLocalizedText('x-default', $default), ...array_values($alternatives)];
    }

    public function namespaces(): array
    {
        return [$this->name->prefix => $this->name->namespace->value];
    }
    public function expandedName(): string
    {
        return $this->name->expanded();
    }
    public function toXml(): string
    {
        $name = $this->name->qualified();
        $items = '';
        foreach ($this->values as $value) {
            $items .= '<rdf:li xml:lang="' . $value->language . '">' . $value->text->escaped() . '</rdf:li>';
        }
        return '<' . $name . '><rdf:Alt>' . $items . '</rdf:Alt></' . $name . '>';
    }
}
