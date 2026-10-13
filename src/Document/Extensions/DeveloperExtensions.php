<?php

declare(strict_types=1);

namespace PDF\Document\Extensions;

use PDF\Serialization\SerializationContext;
use PDF\Specification\Definition\DeveloperExtensionsDictionaryTable50Definition;
use PDF\Specification\ObjectTypeConstraint;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** The catalog Extensions dictionary (ISO 32000-1, 7.12; Adobe supplement, p.24). */
final class DeveloperExtensions extends DictionaryType
{
    public function __construct(DeveloperExtensionEntry ...$extensions)
    {
        parent::__construct(new Entry(new NameType('Type'), new NameType('Extensions')), ...$extensions);
    }

    public function with(Entry $entry): static
    {
        $this->validateEntry($entry);

        return parent::with($entry);
    }

    public function toPdf(SerializationContext $context): string
    {
        $developers = 0;
        foreach ($this->entries() as $entry) {
            $this->validateEntry($entry);
            if ($entry->key->value !== 'Type') {
                $developers++;
            }
        }
        if ($developers === 0) {
            throw new \InvalidArgumentException('An Extensions dictionary requires at least one developer declaration.');
        }

        return parent::toPdf($context);
    }

    private function validateEntry(Entry $entry): void
    {
        (new ExtensionDirectness())->validate($entry->value);
        if ($entry->key->value === 'Type') {
            (new ObjectTypeConstraint('name', fixedName: 'Extensions'))->validate($entry->value);

            return;
        }
        new DeveloperPrefix($entry->key);
        (new ObjectTypeConstraint('dictionary'))->validate($entry->value);
        $target = $entry->value instanceof IndirectReference ? $entry->value->target() : $entry->value;
        if (!$target instanceof DictionaryType) {
            throw new \LogicException('A validated developer extension must be a dictionary.');
        }
        foreach ((new DeveloperExtensionsDictionaryTable50Definition())->entries() as $definition) {
            $value = $target->get($definition->name);
            if ($value === null && $definition->requirement->unconditional()) {
                throw new \InvalidArgumentException('A developer extension requires /' . $definition->name . '.');
            }
            if ($value !== null) {
                $definition->type->validate($value);
            }
        }
    }
}
