<?php

declare(strict_types=1);

namespace PDF\Document\Files;

use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

final class RelatedFileBindings extends DictionaryType
{
    public function __construct(EmbeddedFileBindings $embedded, RelatedFileBinding ...$bindings)
    {
        $entries = [];
        foreach ($bindings as $binding) {
            if (!$embedded->has($binding->key->value) || isset($entries[$binding->key->value])) {
                throw new \InvalidArgumentException('Every RF key must occur once and also be present in EF.');
            }
            $entries[$binding->key->value] = new Entry(new NameType($binding->key->value), $binding->files);
        }
        parent::__construct(...array_values($entries));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Related-file bindings require their explicit EF scope.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Related-file bindings are constructor-controlled.');
    }
}
