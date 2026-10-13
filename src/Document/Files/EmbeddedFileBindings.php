<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** EF maps the same filename keys to explicit indirect embedded streams, Table44/page111. */
final class EmbeddedFileBindings extends DictionaryType
{
    public function __construct(EmbeddedFileBinding ...$bindings)
    {
        $entries = [];
        foreach ($bindings as $binding) {
            if (isset($entries[$binding->key->value])) {
                throw new \InvalidArgumentException('An EF filename key must be unique.');
            }
            $entries[$binding->key->value] = new Entry(new NameType($binding->key->value), new IndirectReference($binding->stream));
        }
        parent::__construct(...array_values($entries));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Embedded-file bindings require their explicit key and stream objects.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Embedded-file bindings are constructor-controlled.');
    }
}
