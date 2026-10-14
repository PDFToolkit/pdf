<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** A scalar XMP property, escaped as element text. */
final readonly class XmpTextProperty implements XmpProperty
{
    public function __construct(public XmpName $name, public XmlText $text)
    {
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
        return '<' . $name . '>' . $this->text->escaped() . '</' . $name . '>';
    }
}
