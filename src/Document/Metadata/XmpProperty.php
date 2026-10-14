<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

/** One RDF property, with its explicit namespace bindings and expanded name. */
interface XmpProperty
{
    /** @return array<string, string> Prefix to namespace URI. */
    public function namespaces(): array;
    public function expandedName(): string;
    public function toXml(): string;
}
