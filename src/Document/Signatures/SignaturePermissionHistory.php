<?php

declare(strict_types=1);

namespace PDF\Document\Signatures;

use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Signatures\Policies\FieldMdpTransform;
use PDF\Document\Signatures\Policies\FrozenSignatureSeedReader;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringType;

/**
 * Intrinsic permission checks for changing a signature field's V, not a general revision classifier.
 * ISO12.8.2.2/12.8.2.4, PDF478/481–482; Adobe Table8.82, PDF43.
 */
final readonly class SignaturePermissionHistory
{
    public IntegerType $permissions;
    /** @var list<array{FrozenSignatureGraph, FieldMdpTransform}> */
    private array $fieldRestrictions;
    private IntegerType $fieldLockPermissions;

    public function __construct(FrozenSignatureGraph $graph)
    {
        $permissions = 3;
        $fieldLockPermissions = 3;
        $restrictions = [];
        $seen = [];
        $rules = new SignatureDocumentSigningRules();
        for ($revision = $graph->revision; $revision !== null; $revision = $revision->previous) {
            $historical = $revision === $graph->revision ? $graph : new FrozenSignatureGraph($revision);
            foreach ($historical->fields() as $field) {
                $value = $field->effective('V');
                if (!$value instanceof IndirectReference || $historical->resolve($value) === null) {
                    continue;
                }
                $record = $historical->record($value);
                $dictionary = $record->dictionary ?? throw new \InvalidArgumentException('Historical field signatures are dictionaries.');
                $contents = $dictionary->get('Contents');
                if ($contents instanceof SignatureContentsReservation) {
                    continue;
                }
                if (!$contents instanceof StringType) {
                    throw new \InvalidArgumentException('Historical field signatures contain their actual signed bytes.');
                }
                $range = $dictionary->get('ByteRange');
                if (!$range instanceof ArrayType || count($range->items) !== 4 || !$range->items[2] instanceof IntegerType || !$range->items[3] instanceof IntegerType) {
                    throw new \InvalidArgumentException('Native signature history has one complete-file ByteRange and Contents gap.');
                }
                $after = $range->items[2]->integer();
                $length = $range->items[3]->integer();
                if ($after < 0 || $length < 0 || $after > PHP_INT_MAX - $length) {
                    throw new \InvalidArgumentException('Historical ByteRange ends are bounded nonnegative integers.');
                }
                $end = $after + $length;
                $key = (string) $record->identifier . '@' . $end;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $source = $rules->signedGraph($graph, $end);
                $signed = $source->revision->binding($record->identifier)->dictionary ?? throw new \InvalidArgumentException('The actual signed snapshot retains its signature dictionary.');
                $methods = $rules->methods($source, $signed);
                if (isset($methods['DocMDP'])) {
                    $permissions = min($permissions, $this->permission($methods['DocMDP']->get('P') ?? new IntegerType(2)));
                }
                if (isset($methods['FieldMDP'])) {
                    $restrictions[] = [$source, $this->fieldTransform($methods['FieldMDP'])];
                }
                foreach ($source->bindings($record->identifier) as $signedField) {
                    $lock = $source->resolve($signedField->dictionary->get('Lock'));
                    if ($lock !== null && !$lock instanceof DictionaryType) {
                        throw new \InvalidArgumentException('Historical signature locks are dictionaries.');
                    }
                    if ($lock instanceof DictionaryType) {
                        $policy = (new FrozenSignatureSeedReader($source))->lock($lock);
                        if ($policy->permissions !== null) {
                            $permissions = min($permissions, $policy->permissions->integer());
                            $fieldLockPermissions = min($fieldLockPermissions, $policy->permissions->integer());
                        }
                    }
                }
            }
        }
        $this->permissions = new IntegerType($permissions);
        $this->fieldRestrictions = $restrictions;
        $this->fieldLockPermissions = new IntegerType($fieldLockPermissions);
    }

    public function assertSigning(FrozenSignatureField $field): void
    {
        if ($this->permissions->integer() === 1) {
            throw new \LogicException('Historical DocMDP/Adobe P1 forbids changing a field value by a later signature.');
        }
        $this->assertFieldRestrictions($field);
    }

    /** ISO 32000-2 12.8.5.3: prove the entire maintenance delta before DocMDP exclusion. */
    public function assertDocumentTimestamp(FrozenSignatureGraph $graph, FrozenSignatureField $field, PendingDocumentTimestamp $pending): void
    {
        if ($this->fieldLockPermissions->integer() === 1) {
            throw new \LogicException('Historical signature field lock P1 remains binding for document timestamps.');
        }
        if ($this->permissions->integer() === 1) {
            (new DocumentTimestampRevisionPolicy())->validate($graph, $field, $pending);
        }
        $this->assertFieldRestrictions($field);
    }

    private function assertFieldRestrictions(FrozenSignatureField $field): void
    {
        foreach ($this->fieldRestrictions as [$source, $restriction]) {
            $names = [$field->qualifiedName];
            foreach ($source->fields() as $original) {
                if ((string) $original->identifier === (string) $field->identifier) {
                    $names[] = $original->qualifiedName;
                }
            }
            foreach ($names as $name) {
                if ($restriction->locks($name)) {
                    throw new \LogicException('A historical FieldMDP transform forbids changing this signature field V, including selected descendants.');
                }
            }
        }
    }

    /** Adobe permissions greater than a previous level are ignored, never granted. */
    public function after(?SignatureFieldLock $lock, ?IntegerType $certification = null): IntegerType
    {
        return new IntegerType(min($this->permissions->integer(), $lock?->permissions?->integer() ?? 3, $certification?->integer() ?? 3));
    }

    private function permission(?\PDF\Syntax\FileStructure\BasicType $value): int
    {
        if (!$value instanceof NumericType || !in_array($value->value, [1, 2, 3, 1.0, 2.0, 3.0], true)) {
            throw new \InvalidArgumentException('Actual historical MDP permissions are1,2 or3.');
        }
        return (int) $value->value;
    }

    private function fieldTransform(DictionaryType $parameters): FieldMdpTransform
    {
        $action = $parameters->get('Action');
        if (!$action instanceof NameType || SignatureLockAction::tryFrom($action->value) === null) {
            throw new \InvalidArgumentException('Actual historical FieldMDP Action is All, Include or Exclude.');
        }
        $names = $parameters->get('Fields');
        if ($names !== null && !$names instanceof ArrayType) {
            throw new \InvalidArgumentException('Historical FieldMDP Fields is an array of exact text names.');
        }
        $texts = [];
        foreach ($names instanceof ArrayType ? $names->items : [] as $name) {
            if (!$name instanceof StringType) {
                throw new \InvalidArgumentException('Historical FieldMDP field names are text strings.');
            }
            $texts[] = new TextString((new TextStringDecoder())->decode($name));
        }
        return new FieldMdpTransform(new SignatureFieldLock(SignatureLockAction::from($action->value), $names === null ? null : new ArrayType(...$texts)));
    }
}
