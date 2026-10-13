<?php

declare(strict_types=1);

namespace PDF\Syntax\DocumentStructure;

use PDF\Specification\Definition\CatalogDictionaryTable28Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Pages;
use PDF\Syntax\DocumentStructure\CatalogDictionary\Type;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;

/** The document's catalog dictionary (ISO 32000-1, table 28). */
final class DocumentCatalog extends SchemaDictionary
{
    public const string TABLE = '28';

    public function __construct(PageTreeNode|IndirectReference $pages, Entry ...$entries)
    {
        foreach ($entries as $entry) {
            if (in_array($entry->key()->value, ['Type', 'Pages'], true)) {
                throw new \InvalidArgumentException('Catalog Type and Pages are supplied by the constructor.');
            }
        }
        parent::__construct(new CatalogDictionaryTable28Definition(), new Type(), new Pages($pages), ...$entries);
    }

    public function with(Entry $entry): static
    {
        if (in_array($entry->key()->value, ['Type', 'Pages'], true)) {
            throw new \InvalidArgumentException('Required catalog entries cannot be replaced.');
        }

        return parent::with($entry);
    }

    public function without(\PDF\Syntax\Object\NameType|string $key): static
    {
        if (in_array($key instanceof \PDF\Syntax\Object\NameType ? $key->value : $key, ['Type', 'Pages'], true)) {
            throw new \InvalidArgumentException('Required catalog entries cannot be removed.');
        }

        return parent::without($key);
    }

    public function entries(): iterable
    {
        foreach (parent::entries() as $entry) {
            if ($entry->key()->value === 'Pages') {
                $value = $entry->value();
                $pages = $value instanceof IndirectReference ? $value->target() : $value;
                if (!$pages instanceof PageTreeNode || $pages->parent() !== null) {
                    throw new \LogicException('Catalog Pages must remain a root page-tree node.');
                }
            }
            yield $entry;
        }
    }
}
