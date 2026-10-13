<?php

declare(strict_types=1);

namespace PDF\Graphics\OptionalContent;

use PDF\Specification\Definition\OptionalContentGroupDictionaryTable98Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Document\CatalogComponent;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;

/** OCG identity, immutable declaration and explicit document-scoped visibility. */
final class OptionalContentGroup extends SchemaDictionary implements OptionalContentObject, CatalogComponent, \PDF\Document\VersionedComponent
{
    public function validateVersion(\PDF\Syntax\FileStructure\EffectivePdfVersion $version): void
    {
        $version->require(\PDF\Syntax\FileStructure\PdfVersion::PDF15, 'Optional content group');
    }

    public const string TABLE = '98';

    public function __construct(public readonly TextString $name, public readonly Intents $intents = new Intents(Intent::View), public readonly ?GroupUsage $usage = null)
    {
        if (in_array(Intent::All, $intents->intents, true)) {
            throw new \InvalidArgumentException('Conforming OCG writers may declare only View and Design intents (8.11.2.3).');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('OCG')), new Entry(new NameType('Name'), $name), new Entry(new NameType('Intent'), $intents->value())];
        if ($usage !== null) {
            $entries[] = new Entry(new NameType('Usage'), $usage);
        }
        parent::__construct(new OptionalContentGroupDictionaryTable98Definition(), ...$entries);
    }

    public function groups(): GroupCollection
    {
        return new GroupCollection($this);
    }

    public function visible(GroupStates $states): bool
    {
        return $states->decision($this) ?? true;
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $properties = $catalog->get('OCProperties');
        $properties = $properties instanceof IndirectReference ? $properties->target() : $properties;
        $groups = $properties instanceof DictionaryType ? $properties->get('OCGs') : null;
        if ($groups instanceof ArrayType) {
            foreach ($groups->items as $reference) {
                if ($reference instanceof IndirectReference && $reference->target() === $this) {
                    return;
                }
            }
        }
        throw new \InvalidArgumentException('Every OCG must have its canonical indirect identity in catalog OCProperties/OCGs.');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('An OCG declaration is immutable; its state lives in an explicit GroupStates object.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('An OCG declaration is immutable.');
    }
}
