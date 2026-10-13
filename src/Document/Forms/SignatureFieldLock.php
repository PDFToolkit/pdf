<?php

declare(strict_types=1);

namespace PDF\Document\Forms;

use PDF\Specification\Adobe\Definition\SignatureFieldLockDictionaryTable8_82Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;

/** ISO p.455 and Adobe p.43. This declares restrictions; it does not sign the file. */
final class SignatureFieldLock extends SchemaDictionary
{
    public function __construct(public readonly SignatureLockAction $action, ?ArrayType $fields = null, public readonly ?IntegerType $permissions = null)
    {
        if ($action !== SignatureLockAction::All && $fields === null) {
            throw new \InvalidArgumentException('Include/Exclude signature locks require a Fields array.');
        }
        foreach ($fields === null ? [] : $fields->items as $field) {
            if (!$field instanceof TextString) {
                throw new \InvalidArgumentException('Signature lock field names are text strings.');
            }
        }
        if ($permissions !== null && !in_array($permissions->value, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Adobe signature lock permissions are 1, 2 or 3.');
        }
        $entries = [new Entry(new NameType('Type'), new NameType('SigFieldLock')), new Entry(new NameType('Action'), new NameType($action->value))];
        if ($fields !== null) {
            $entries[] = new Entry(new NameType('Fields'), $fields);
        }
        if ($permissions !== null) {
            $entries[] = new Entry(new NameType('P'), $permissions);
        }
        parent::__construct(new SignatureFieldLockDictionaryTable8_82Definition(), ...$entries);
    }

    /** Adobe P can deny permissions already in effect, but cannot add them. */
    public function effectivePermissions(IntegerType $previous = new IntegerType(3)): IntegerType
    {
        if (!in_array($previous->value, [1, 2, 3], true)) {
            throw new \InvalidArgumentException('Existing MDP permissions are 1, 2 or 3.');
        }
        return new IntegerType(min($previous->integer(), $this->permissions === null ? $previous->integer() : $this->permissions->integer()));
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('Signature lock entries are supplied by its constructor.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('Signature lock entries are supplied by its constructor.');
    }
}
