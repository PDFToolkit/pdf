<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\FieldMDPTransformParametersDictionaryTable256Definition;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\ArrayType;

/** ISO 12.8.2.4, PDF pp.481–482: direct copy of Action/Fields from the field lock. */
final class FieldMdpTransform extends ImmutableSignatureDictionary implements SignatureTransform, VersionedComponent
{
    public readonly SignatureFieldNames $fields;
    public function __construct(public readonly SignatureFieldLock $lock)
    {
        $selected = $lock->get('Fields');
        $names = [];
        foreach ($selected instanceof ArrayType ? $selected->items : [] as $name) {
            if (!$name instanceof TextString) {
                throw new \InvalidArgumentException('FieldMDP names are text strings.');
            }
            $names[] = $name;
        }
        $this->fields = new SignatureFieldNames(...$names);
        $entries = [new Entry(new NameType('Type'), new NameType('TransformParams')), new Entry(new NameType('Action'), new NameType($lock->action->value)), new Entry(new NameType('V'), new NameType('1.2'))];
        if ($selected !== null) {
            $entries[] = new Entry(new NameType('Fields'), $this->fields);
        }
        parent::__construct(new FieldMDPTransformParametersDictionaryTable256Definition(), ...$entries);
    }
    public function locks(string $qualifiedName): bool
    {
        return match ($this->lock->action) {
            SignatureLockAction::All => true,
            SignatureLockAction::Include => $this->fields->selects($qualifiedName),
            SignatureLockAction::Exclude => !$this->fields->selects($qualifiedName),
        };
    }
    public function method(): SignatureTransformMethod
    {
        return SignatureTransformMethod::FieldMDP;
    }
    public function parameters(): self
    {
        return $this;
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF15, 'FieldMDP signature reference');
    }
}
