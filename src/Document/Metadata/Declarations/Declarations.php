<?php

declare(strict_types=1);

namespace PDF\Document\Metadata\Declarations;

use PDF\Document\Metadata\XmpProperty;

/** PDF-Declarations.pdf pp.7–9: unordered RDF bag, exact pdfd namespace, at least one entry. */
final readonly class Declarations implements XmpProperty
{
    public const string NAMESPACE = 'http://pdfa.org/declarations/';
    /** @var non-empty-list<Declaration> */
    public array $declarations;
    public function __construct(Declaration $first, Declaration ...$others)
    {
        $this->declarations = [$first, ...array_values($others)];
    }
    public function namespaces(): array
    {
        return ['pdfd' => self::NAMESPACE];
    }
    public function expandedName(): string
    {
        return '{' . self::NAMESPACE . '}declarations';
    }
    public function toXml(): string
    {
        return '<pdfd:declarations><rdf:Bag>' . implode('', array_map(static fn (Declaration $declaration): string => $declaration->toXml(), $this->declarations)) . '</rdf:Bag></pdfd:declarations>';
    }
}
