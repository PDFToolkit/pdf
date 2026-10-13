<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\URTransformParametersDictionaryTable255Definition;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\NameType;

/** ISO12.8.2.3, PDF480–481: declarations and native saving rules; trust remains external. */
final class UsageRightsTransform extends ImmutableSignatureDictionary implements SignatureTransform, VersionedComponent
{
    /** @var list<UsageRight> */
    public readonly array $rights;
    public function __construct(public readonly UsageRightsMode $mode, public readonly bool $restrictConsumers = false, ?TextString $message = null, UsageRight ...$rights)
    {
        $groups = [];
        $seen = [];
        foreach ($rights as $right) {
            if (isset($seen[$right->value])) {
                throw new \InvalidArgumentException('Usage rights are distinct.');
            }
            if ($right->requiresUr3() && $mode !== UsageRightsMode::ByteRange) {
                throw new \InvalidArgumentException('This usage right requires a UR3 permissions signature.');
            }
            $seen[$right->value] = true;
            $groups[$right->key()][] = new NameType($right->name());
        }
        $this->rights = array_values($rights);
        $entries = [new Entry(new NameType('Type'), new NameType('TransformParams')), new Entry(new NameType('V'), new NameType('2.2'))];
        foreach ($groups as $key => $names) {
            $entries[] = new Entry(new NameType($key), new ArrayType(...$names));
        }
        if ($restrictConsumers) {
            $entries[] = new Entry(new NameType('P'), new BooleanType(true));
        }
        if ($message !== null) {
            $entries[] = new Entry(new NameType('Msg'), $message);
        }
        parent::__construct(new URTransformParametersDictionaryTable255Definition(), ...$entries);
    }
    public function allows(UsageRight $right): bool
    {
        return in_array($right, $this->rights, true);
    }
    public function allowsSaving(bool $signatureOperation = false): bool
    {
        if ($this->allows(UsageRight::FullSave)) {
            return true;
        }
        foreach ($this->rights as $right) {
            if ($right === UsageRight::ModifySignature) {
                if ($signatureOperation) {
                    return true;
                }
                continue;
            }
            if ($right->modifiesDocument() && ($this->mode === UsageRightsMode::Legacy || $this->restrictConsumers || $right->implicitlySavesAnnotations())) {
                return true;
            }
        }
        return false;
    }
    public function method(): SignatureTransformMethod
    {
        return SignatureTransformMethod::UR;
    }
    public function parameters(): self
    {
        return $this;
    }
    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require($this->mode === UsageRightsMode::ByteRange ? PdfVersion::PDF16 : PdfVersion::PDF15, 'Usage-rights signature');
        if ($this->restrictConsumers) {
            $version->require(PdfVersion::PDF16, 'Restrictive usage-rights P');
        }
        foreach ($this->rights as $right) {
            if ($right->requiresUr3() || $right->key() === 'EF') {
                $version->require(PdfVersion::PDF16, 'Usage right ' . $right->value);
            }
        }
    }
}
