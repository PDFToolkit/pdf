<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Specification\Definition\MarkedContentReferenceDictionaryTable324Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Explicit MCR, including Pg and optional Stm/StmOwn (table 324, PDF page 569). */
final class MarkedContentReference extends SchemaDictionary
{
    public const string TABLE = '324';

    public function __construct(public readonly MarkedContentContainer $container, public readonly MarkedContentIdentifier $identifier)
    {
        $entries = [
            new Entry(new NameType('Type'), new NameType('MCR')),
            new Entry(new NameType('Pg'), new IndirectReference($container->page)),
            new Entry(new NameType('MCID'), $identifier->number),
        ];
        if ($container->stream !== null) {
            $entries[] = new Entry(new NameType('Stm'), new IndirectReference($container->stream));
        }
        if ($container->streamOwner !== null) {
            $entries[] = new Entry(new NameType('StmOwn'), new IndirectReference($container->streamOwner));
        }
        parent::__construct(new MarkedContentReferenceDictionaryTable324Definition(), ...$entries);
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('An MCR is determined by its explicit container and identifier.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('An MCR cannot lose its container or identifier.');
    }
}
