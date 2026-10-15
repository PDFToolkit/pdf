<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** Prove new pages are exact instances of historical invisible templates, not arbitrary page additions. */
final readonly class ProtectedPageTemplateChanges
{
    /** @return list<string> Fresh page identities owned by this operation. */
    public function classify(ProtectedRevisionChanges $changes, SignaturePermissionHistory $history): array
    {
        $oldReference = $changes->prior->catalog->get('Pages');
        $reference = $changes->current->catalog->get('Pages');
        if (!$oldReference instanceof IndirectReference || !$reference instanceof IndirectReference
            || !$changes->same($oldReference, $reference)) {
            throw new \LogicException('Template spawning preserves the page-tree root identity.');
        }
        $oldRecord = $changes->prior->record($oldReference);
        $record = $changes->current->record($reference);
        $old = $oldRecord->dictionary ?? throw new \LogicException('Page-tree roots are dictionaries.');
        $new = $record->dictionary ?? throw new \LogicException('Page-tree roots are dictionaries.');
        $oldKids = $changes->prior->resolve($old->get('Kids'));
        $kids = $changes->current->resolve($new->get('Kids'));
        if (!$oldKids instanceof ArrayType || !$kids instanceof ArrayType) {
            throw new \LogicException('Page-tree roots retain their Kids arrays.');
        }
        if (count($oldKids->items) > 4096 || count($kids->items) > 4096) {
            throw new \LengthException('Protected template spawning has bounded page-tree children.');
        }
        if ($changes->same($oldKids, $kids)) {
            return [];
        }
        if ($history->permissions->integer() < 2) {
            throw new \LogicException('Historical DocMDP/Adobe P1 forbids template instantiation.');
        }
        if (!$changes->sameDictionary($old, $new, ['Kids', 'Count'])) {
            throw new \LogicException('Template spawning preserves page-tree properties and inheritance.');
        }
        $oldIds = [];
        foreach ($oldKids->items as $child) {
            if (!$child instanceof IndirectReference) {
                throw new \LogicException('Page-tree children have explicit indirect identities.');
            }
            $oldIds[] = (string) $changes->prior->record($child)->identifier;
        }
        $templates = new FrozenPageTemplates($changes->prior);
        $kept = [];
        $created = [];
        foreach ($kids->items as $child) {
            if (!$child instanceof IndirectReference) {
                throw new \LogicException('Page-tree children have explicit indirect identities.');
            }
            $page = $changes->current->record($child);
            $id = (string) $page->identifier;
            if (in_array($id, $oldIds, true)) {
                $kept[] = $id;
                continue;
            }
            if (in_array($id, $created, true)) {
                throw new \LogicException('An instantiated page has exactly one page-tree owner.');
            }
            $dictionary = $page->dictionary ?? throw new \LogicException('An instantiated page is a dictionary.');
            $name = $dictionary->get('TemplateInstantiated');
            $type = $dictionary->get('Type');
            $parent = $dictionary->get('Parent');
            if (!$name instanceof NameType || !$type instanceof NameType || $type->value !== 'Page'
                || !$parent instanceof IndirectReference || (string) $changes->current->record($parent)->identifier !== (string) $record->identifier) {
                throw new \LogicException('An instantiated page retains its exact template name and root parent.');
            }
            $template = $templates->named($name)->dictionary ?? throw new \LogicException('A named template is a dictionary.');
            if ($template->has('Annots') || !$changes->sameDictionary($template, $dictionary, ['Type', 'Parent', 'TemplateInstantiated'])) {
                throw new \LogicException('Protected spawning copies the exact template; annotation/field cloning requires a separate proof.');
            }
            $changes->create($page->identifier);
            $created[] = $id;
        }
        $oldCount = $old->get('Count');
        $count = $new->get('Count');
        if ($kept !== $oldIds || $created === [] || !$oldCount instanceof IntegerType || !$count instanceof IntegerType
            || $count->integer() - $oldCount->integer() !== count($created)) {
            throw new \LogicException('Template spawning retains existing page order and adds the exact new page count.');
        }
        $changes->change($record->identifier);
        $entry = $new->get('Kids');
        if ($entry instanceof IndirectReference) {
            $children = $changes->current->record($entry);
            $before = $old->get('Kids');
            if ($before instanceof IndirectReference && $changes->same($before, $entry)) {
                $changes->assertExclusiveReferences($children->identifier, [(string) $record->identifier]);
                $changes->change($children->identifier);
            } else {
                $changes->create($children->identifier);
            }
        }
        return $created;
    }
}
