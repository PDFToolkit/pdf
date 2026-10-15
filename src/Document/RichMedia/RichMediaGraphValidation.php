<?php

declare(strict_types=1);

namespace PDF\Document\RichMedia;

use PDF\Document\Annotations\AnnotationPageIndex;
use PDF\Document\DocumentGraphContext;
use PDF\Document\Files\FileSpecificationRules;
use PDF\Document\Navigation\NavigationLimits;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** EC3 #58/#59/#145/#163/#166, physical pages 716–726: effective annotation-owned relationships. */
final readonly class RichMediaGraphValidation
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function validate(DocumentGraphContext $graph, EffectivePdfVersion $version): void
    {
        /** @var array<string,string> $owners */
        $owners = [];
        foreach ((new AnnotationPageIndex($graph, $this->limits))->annotations as $annotation) {
            $scope = $annotation->graph;
            $subtype = $this->value($annotation->dictionary, 'Subtype', $scope);
            if (!$subtype instanceof NameType || $subtype->value !== 'RichMedia') {
                continue;
            }
            $version->require(PdfVersion::PDF20, 'RichMedia annotations');
            $content = $this->ownedDictionary($annotation->dictionary, 'RichMediaContent', true, $scope, $annotation->identity, $owners);
            $settings = $this->ownedDictionary($annotation->dictionary, 'RichMediaSettings', false, $scope, $annotation->identity, $owners);
            if ($content === null) {
                throw new \LogicException('A required RichMediaContent was checked.');
            }
            $configurations = $this->references($content, 'Configurations', $scope, true);
            $views = $this->references($content, 'Views', $scope);
            $assetsEntry = $scope->entry($content, 'Assets');
            $assets = $assetsEntry === null ? [] : $scope->nameTree($assetsEntry);
            foreach ($assets as $asset) {
                $file = $this->file($asset, $scope);
                if ($scope->entry($file, 'EF') === null) {
                    throw new \InvalidArgumentException('RichMedia Assets name-tree entries require embedded file specifications.');
                }
            }
            foreach ($configurations as $configuration) {
                $dictionary = $scope->dictionary($configuration) ?? throw new \LogicException('A configuration reference was checked.');
                foreach ($this->references($dictionary, 'Instances', $scope) as $instance) {
                    $dictionary = $scope->dictionary($instance) ?? throw new \LogicException('An instance reference was checked.');
                    $asset = $scope->entry($dictionary, 'Asset');
                    if ($asset === null) {
                        throw new \InvalidArgumentException('RichMediaInstance requires its Asset file specification.');
                    }
                    // EC3 #58: this primary asset need not occur in the Assets name tree.
                    $this->file($asset, $scope);
                }
            }
            $activationEntry = $settings === null ? null : $scope->entry($settings, 'Activation');
            if ($activationEntry === null) {
                continue;
            }
            $activation = $this->dictionary($activationEntry, $scope, 'Activation');
            foreach (['Configuration' => $configurations, 'View' => $views] as $key => $members) {
                $selected = $scope->entry($activation, $key);
                if ($selected !== null && (!$selected instanceof IndirectReference || !$this->contains($members, $selected, $scope))) {
                    throw new \InvalidArgumentException('RichMedia Activation.' . $key . ' must select an entry of its annotation content array.');
                }
            }
            foreach ($this->references($activation, 'Scripts', $scope) as $script) {
                $this->file($script, $scope);
                if (!$this->contains(array_values($assets), $script, $scope)) {
                    throw new \InvalidArgumentException('Every RichMedia activation script must occur in its annotation Assets name tree (EC3 #59).');
                }
            }
        }
    }

    /** @param array<string,string> $owners */
    private function ownedDictionary(DictionaryType $annotation, string $key, bool $required, DocumentGraphContext $graph, string $owner, array &$owners): ?DictionaryType
    {
        $entry = $graph->entry($annotation, $key);
        if ($entry === null) {
            if ($required) {
                throw new \InvalidArgumentException('RichMedia annotation requires /' . $key . '.');
            }
            return null;
        }
        $dictionary = $this->dictionary($entry, $graph, $key);
        if ($entry instanceof IndirectReference) {
            $identity = (string) ($graph->identifier($entry) ?? throw new \LogicException('An indirect RichMedia dictionary has its native identity.'));
            if (isset($owners[$identity]) && $owners[$identity] !== $owner) {
                throw new \InvalidArgumentException('RichMediaContent and RichMediaSettings must be unique to each annotation (EC3 #163).');
            }
            $owners[$identity] = $owner;
        }
        return $dictionary;
    }

    /** @return list<IndirectReference> */
    private function references(DictionaryType $dictionary, string $key, DocumentGraphContext $graph, bool $nonemptyRequired = false): array
    {
        $entry = $this->value($dictionary, $key, $graph);
        if ($entry === null && !$nonemptyRequired) {
            return [];
        }
        if (!$entry instanceof ArrayType || ($nonemptyRequired && count($entry) === 0)) {
            throw new \InvalidArgumentException('RichMedia /' . $key . ' requires ' . ($nonemptyRequired ? 'a nonempty' : 'an') . ' array of indirect dictionary references.');
        }
        if (count($entry) > $this->limits->maxNodes) {
            throw new \LengthException('RichMedia references exceed the explicit document construction budget.');
        }
        $references = [];
        foreach ($entry as $value) {
            if (!$value instanceof IndirectReference) {
                throw new \InvalidArgumentException('RichMedia /' . $key . ' requires indirect dictionary references.');
            }
            $this->dictionary($value, $graph, $key);
            $references[] = $value;
        }
        return $references;
    }

    private function file(BasicType $reference, DocumentGraphContext $graph): DictionaryType
    {
        if (!$reference instanceof IndirectReference) {
            throw new \InvalidArgumentException('RichMedia assets and scripts require indirect file-specification references.');
        }
        $dictionary = $this->dictionary($reference, $graph, 'file specification');
        (new FileSpecificationRules())->validate($dictionary, $graph);
        return $dictionary;
    }

    private function dictionary(BasicType $value, DocumentGraphContext $graph, string $key): DictionaryType
    {
        $dictionary = $graph->dictionary($value);
        if ($dictionary === null || $graph->resolve($value) instanceof StreamObject) {
            throw new \InvalidArgumentException('RichMedia ' . $key . ' requires a dictionary, not a stream.');
        }
        return $dictionary;
    }

    /** @param list<BasicType> $members */
    private function contains(array $members, BasicType $selected, DocumentGraphContext $graph): bool
    {
        foreach ($members as $member) {
            if ($graph->sameObject($member, $selected)) {
                return true;
            }
        }
        return false;
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $value = $graph->entry($dictionary, $key);
        return $value === null ? null : $graph->resolve($value);
    }
}
