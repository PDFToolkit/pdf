<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Document\Fonts\CMaps\PredefinedCMap;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** One inspection owns its worklist, contextual identities and budgets. No global key-name scan. */
final class WtPdfDeprecatedTraversal
{
    /** @var list<WtPdfDeprecatedNode> */
    private array $pending = [];
    /** @var \SplObjectStorage<BasicType,array<string,true>> */
    private \SplObjectStorage $visited;
    private int $contextualObjects = 0;
    /** @var array<string,true> */
    private array $reported = [];
    /** @var list<WtPdfFinding> */
    private array $results = [];
    private int $edges = 0;
    private int $operations = 0;

    public function __construct(DictionaryType $catalog, ?WtPdfDeprecatedFileContext $file, private readonly WtPdfDeprecatedCatalogue $catalogue, private readonly WtPdfDeprecatedLimits $limits)
    {
        $this->visited = new \SplObjectStorage();
        $this->pending[] = new WtPdfDeprecatedNode($catalog, WtPdfDeprecatedDomain::Catalog, 'Catalog');
        if ($file === null) {
            $this->results[] = new WtPdfFinding('6.2', 10, WtPdfFindingStatus::AdditionalValidationRequired, 'Deprecated-feature audit: no application-supplied trailer context; document Info and encryption declarations have not been inspected.');
        } else {
            $this->pending[] = new WtPdfDeprecatedNode($file->trailer, WtPdfDeprecatedDomain::Trailer, 'Trailer');
        }
        try {
            while ($this->pending !== []) {
                $this->visit(array_pop($this->pending));
            }
        } catch (\OverflowException $error) {
            $this->results[] = new WtPdfFinding('6.2', 10, WtPdfFindingStatus::AdditionalValidationRequired, 'Deprecated-feature audit is incomplete: ' . $error->getMessage());
        }
    }

    /** @return list<WtPdfFinding> */
    public function findings(): array
    {
        return $this->results;
    }

    private function visit(WtPdfDeprecatedNode $node): void
    {
        $value = $this->resolve($node->value);
        if ($value instanceof NullType) {
            return;
        }
        $key = $node->domain->name . ':' . $node->fieldType;
        $contexts = $this->visited->offsetExists($value) ? $this->visited[$value] : [];
        if (isset($contexts[$key])) {
            return;
        }
        if ($this->contextualObjects >= $this->limits->maximumObjects || $node->depth > $this->limits->maximumDepth) {
            throw new \OverflowException('The selected contextual object/depth budget was exceeded.');
        }
        ++$this->contextualObjects;
        $contexts[$key] = true;
        $this->visited[$value] = $contexts;
        if ($value instanceof ArrayType) {
            $this->charge(count($value));
            foreach ($value->items as $index => $item) {
                if ($node->domain === WtPdfDeprecatedDomain::AttributeAssociations && $this->resolve($item) instanceof IntegerType) {
                    $this->recommend('structure-revisions', $node);
                } else {
                    $this->enqueue($item, $node->domain, $node, '[' . $index . ']');
                }
            }
            return;
        }
        if ($node->domain === WtPdfDeprecatedDomain::Content) {
            $this->content($value, $node);
            return;
        }
        if ($node->domain === WtPdfDeprecatedDomain::CMap && $value instanceof NameType) {
            if (PredefinedCMap::tryFrom($value->value)?->ordering() === 'Korea1') {
                $this->recommend('cid-collections', $node);
            }
            return;
        }
        $dictionary = $value instanceof DictionaryType ? $value : ($value instanceof StreamObject ? $value->dictionary() : null);
        if ($dictionary === null) {
            return;
        }
        $this->charge(count($dictionary));
        $name = fn (string $key): ?string => $this->name($dictionary, $key);
        $has = fn (string $key): bool => $this->entry($dictionary, $key) !== null;
        $edge = fn (string $key, WtPdfDeprecatedDomain $domain) => $this->edge($dictionary, $key, $domain, $node);
        switch ($node->domain) {
            case WtPdfDeprecatedDomain::Catalog:
                $this->when($has('NeedsRendering'), 'catalog-needs-rendering', $node);
                $this->when($has('SpiderInfo'), 'web-capture', $node);
                foreach (['Pages' => WtPdfDeprecatedDomain::Pages, 'Names' => WtPdfDeprecatedDomain::Names, 'AcroForm' => WtPdfDeprecatedDomain::AcroForm, 'StructTreeRoot' => WtPdfDeprecatedDomain::StructureRoot, 'ViewerPreferences' => WtPdfDeprecatedDomain::ViewerPreferences, 'MarkInfo' => WtPdfDeprecatedDomain::MarkInfo, 'Perms' => WtPdfDeprecatedDomain::Permissions, 'Legal' => WtPdfDeprecatedDomain::Legal, 'OpenAction' => WtPdfDeprecatedDomain::Action, 'AA' => WtPdfDeprecatedDomain::CatalogActions, 'Outlines' => WtPdfDeprecatedDomain::Outline, 'AF' => WtPdfDeprecatedDomain::FileSpec] as $key => $domain) {
                    $edge($key, $domain);
                }
                break;
            case WtPdfDeprecatedDomain::Pages:
                if ($name('Type') === 'Pages') {
                    $edge('Kids', WtPdfDeprecatedDomain::Pages);
                    $edge('Resources', WtPdfDeprecatedDomain::Resources);
                } elseif ($name('Type') === 'Page') {
                    $this->when($has('ID') || $has('PZ'), 'web-capture', $node);
                    $this->when($has('SeparationInfo'), 'page-separations', $node);
                    foreach (['Resources' => WtPdfDeprecatedDomain::Resources, 'Contents' => WtPdfDeprecatedDomain::Content, 'Annots' => WtPdfDeprecatedDomain::Annotation, 'AA' => WtPdfDeprecatedDomain::PageActions, 'PresSteps' => WtPdfDeprecatedDomain::NavigationNode, 'AF' => WtPdfDeprecatedDomain::FileSpec] as $key => $domain) {
                        $edge($key, $domain);
                    }
                }
                break;
            case WtPdfDeprecatedDomain::Resources:
                $this->when($has('ProcSet'), 'resources-procset', $node);
                foreach (['Font' => WtPdfDeprecatedDomain::Font, 'XObject' => WtPdfDeprecatedDomain::XObject, 'ExtGState' => WtPdfDeprecatedDomain::ExtGState, 'Pattern' => WtPdfDeprecatedDomain::Pattern] as $key => $domain) {
                    $this->map($dictionary, $key, $domain, $node);
                }
                break;
            case WtPdfDeprecatedDomain::XObject:
            case WtPdfDeprecatedDomain::TrapAppearance:
                $subtype = $name('Subtype');
                if (in_array($subtype, ['Image', 'Form'], true)) {
                    $this->when($has('Name'), 'xobject-name', $node);
                    $mask = $this->entry($dictionary, 'ImageMask');
                    $this->when($has('OPI') && !($subtype === 'Image' && $mask instanceof BooleanType && $mask->value), 'xobject-opi', $node);
                    $this->when($subtype === 'Image' && $has('ID'), 'web-capture', $node);
                    $edge('AF', WtPdfDeprecatedDomain::FileSpec);
                }
                if ($subtype === 'Form') {
                    $edge('Resources', WtPdfDeprecatedDomain::Resources);
                    $this->content($value, $node);
                }
                if ($node->domain === WtPdfDeprecatedDomain::TrapAppearance) {
                    $this->recommend('trapping-appearance', $node);
                    if (!$value instanceof StreamObject) {
                        foreach ($dictionary->entries() as $entry) {
                            $this->enqueue($entry->value, WtPdfDeprecatedDomain::TrapAppearance, $node, '/' . $entry->key->value);
                        }
                    }
                }
                break;
            case WtPdfDeprecatedDomain::Pattern:
                $edge('Resources', WtPdfDeprecatedDomain::Resources);
                if ($value instanceof StreamObject) {
                    $this->content($value, $node);
                }
                $edge('ExtGState', WtPdfDeprecatedDomain::ExtGState);
                break;
            case WtPdfDeprecatedDomain::Font:
                $subtype = $name('Subtype');
                $this->when(in_array($subtype, ['Type1', 'MMType1', 'TrueType', 'Type3'], true) && $has('Name'), 'font-name', $node);
                if (in_array($subtype, ['Type1', 'MMType1'], true)) {
                    $edge('FontDescriptor', WtPdfDeprecatedDomain::Type1Descriptor);
                } elseif (in_array($subtype, ['CIDFontType0', 'CIDFontType2'], true)) {
                    $edge('FontDescriptor', WtPdfDeprecatedDomain::CidDescriptor);
                    $edge('CIDSystemInfo', WtPdfDeprecatedDomain::CidSystemInfo);
                } elseif ($subtype === 'Type0') {
                    $edge('DescendantFonts', WtPdfDeprecatedDomain::Font);
                    $edge('Encoding', WtPdfDeprecatedDomain::CMap);
                } elseif ($subtype === 'Type3') {
                    $edge('Resources', WtPdfDeprecatedDomain::Resources);
                    $this->map($dictionary, 'CharProcs', WtPdfDeprecatedDomain::Content, $node);
                }
                $edge('ToUnicode', WtPdfDeprecatedDomain::CMap);
                break;
            case WtPdfDeprecatedDomain::Type1Descriptor:
                $this->when($has('CharSet'), 'font-type1-charset', $node);
                break;
            case WtPdfDeprecatedDomain::CidDescriptor:
                $this->when($has('CIDSet'), 'font-cidset', $node);
                $this->map($dictionary, 'FD', WtPdfDeprecatedDomain::CidDescriptor, $node);
                break;
            case WtPdfDeprecatedDomain::CidSystemInfo:
                $registry = $this->entry($dictionary, 'Registry');
                $ordering = $this->entry($dictionary, 'Ordering');
                $this->when($registry instanceof StringType && $registry->value === 'Adobe' && $ordering instanceof StringType && in_array($ordering->value, ['Japan2', 'Korea1'], true), 'cid-collections', $node);
                break;
            case WtPdfDeprecatedDomain::CMap:
                $edge('CIDSystemInfo', WtPdfDeprecatedDomain::CidSystemInfo);
                $edge('UseCMap', WtPdfDeprecatedDomain::CMap);
                if ($value instanceof StreamObject) {
                    $this->external('cmap-character-collections', $node);
                }
                break;
            case WtPdfDeprecatedDomain::ExtGState:
                $this->when($has('TR') || $has('TR2'), 'extgstate-transfer', $node);
                $blend = $this->entry($dictionary, 'BM');
                $this->when($blend instanceof ArrayType, 'blend-mode-array', $node);
                if ($blend instanceof ArrayType) {
                    $this->charge(count($blend));
                }
                foreach ($blend instanceof ArrayType ? $blend->items : ($blend === null ? [] : [$blend]) as $mode) {
                    $mode = $this->resolve($mode);
                    $this->when($mode instanceof NameType && $mode->value === 'Compatible', 'blend-mode-compatible', $node);
                }
                $font = $this->entry($dictionary, 'Font');
                if ($font instanceof ArrayType && isset($font->items[0])) {
                    $this->enqueue($font->items[0], WtPdfDeprecatedDomain::Font, $node, '/Font[0]');
                }
                $softMask = $this->dictionary($dictionary->get('SMask'));
                if ($softMask !== null) {
                    $this->edge($softMask, 'G', WtPdfDeprecatedDomain::XObject, $node);
                }
                break;
            case WtPdfDeprecatedDomain::Annotation:
                $subtype = $name('Subtype');
                $this->when(in_array($subtype, ['Sound', 'Movie', 'TrapNet'], true), 'annotations-legacy', $node);
                if (in_array($subtype, ['Link', 'Screen', 'Widget'], true)) {
                    $edge('A', WtPdfDeprecatedDomain::Action);
                }
                if (in_array($subtype, ['Screen', 'Widget'], true)) {
                    $edge('AA', WtPdfDeprecatedDomain::AnnotationActions);
                }
                if ($subtype === 'FileAttachment') {
                    $edge('FS', WtPdfDeprecatedDomain::FileSpec);
                }
                $edge('AF', WtPdfDeprecatedDomain::FileSpec);
                $appearance = $this->dictionary($dictionary->get('AP'));
                if ($appearance !== null) {
                    $this->charge(count($appearance));
                    foreach (['N', 'R', 'D'] as $key) {
                        $this->edge($appearance, $key, $subtype === 'TrapNet' ? WtPdfDeprecatedDomain::TrapAppearance : WtPdfDeprecatedDomain::Appearance, $node);
                    }
                }
                if ($subtype === 'Widget') {
                    $edge('Parent', WtPdfDeprecatedDomain::Field);
                    $this->field($dictionary, $node);
                }
                break;
            case WtPdfDeprecatedDomain::Appearance:
                if ($value instanceof StreamObject) {
                    $edge('Resources', WtPdfDeprecatedDomain::Resources);
                    $this->content($value, $node);
                } else {
                    foreach ($dictionary->entries() as $entry) {
                        $this->enqueue($entry->value, WtPdfDeprecatedDomain::Appearance, $node, '/' . $entry->key->value);
                    }
                }
                break;
            case WtPdfDeprecatedDomain::Action:
                $kind = $name('S');
                $this->when(in_array($kind, ['Sound', 'Movie'], true), 'actions-legacy', $node);
                $this->when($kind === 'Launch' && ($has('Win') || $has('Mac') || $has('Unix')), 'launch-os', $node);
                if (in_array($kind, ['GoToR', 'GoToE', 'Launch', 'Thread', 'ImportData', 'SubmitForm'], true)) {
                    $edge('F', WtPdfDeprecatedDomain::FileSpec);
                }
                $edge('Next', WtPdfDeprecatedDomain::Action);
                break;
            case WtPdfDeprecatedDomain::CatalogActions:
            case WtPdfDeprecatedDomain::PageActions:
            case WtPdfDeprecatedDomain::AnnotationActions:
            case WtPdfDeprecatedDomain::FieldActions:
                $keys = match ($node->domain) {
                    WtPdfDeprecatedDomain::CatalogActions => ['WC', 'WS', 'DS', 'WP', 'DP'],
                    WtPdfDeprecatedDomain::PageActions => ['O', 'C'],
                    WtPdfDeprecatedDomain::AnnotationActions => ['E', 'X', 'D', 'U', 'Fo', 'Bl', 'PO', 'PC', 'PV', 'PI'],
                    WtPdfDeprecatedDomain::FieldActions => ['K', 'F', 'V', 'C'],
                };
                foreach ($keys as $key) {
                    $edge($key, WtPdfDeprecatedDomain::Action);
                }
                break;
            case WtPdfDeprecatedDomain::Outline:
                $edge('A', WtPdfDeprecatedDomain::Action);
                $edge('First', WtPdfDeprecatedDomain::Outline);
                $edge('Next', WtPdfDeprecatedDomain::Outline);
                break;
            case WtPdfDeprecatedDomain::NavigationNode:
                foreach (['Next', 'Prev'] as $key) {
                    $edge($key, WtPdfDeprecatedDomain::NavigationNode);
                }
                foreach (['NA', 'PA'] as $key) {
                    $edge($key, WtPdfDeprecatedDomain::Action);
                }
                break;
            case WtPdfDeprecatedDomain::Names:
                $this->when($has('AlternatePresentations'), 'alternate-presentations', $node);
                $this->when($has('IDS') || $has('URLS'), 'web-capture', $node);
                $edge('EmbeddedFiles', WtPdfDeprecatedDomain::EmbeddedNameTree);
                $edge('JavaScript', WtPdfDeprecatedDomain::ActionNameTree);
                break;
            case WtPdfDeprecatedDomain::EmbeddedNameTree:
            case WtPdfDeprecatedDomain::ActionNameTree:
                $edge('Kids', $node->domain);
                $names = $this->entry($dictionary, 'Names');
                if ($names instanceof ArrayType) {
                    $this->charge(count($names));
                    for ($index = 1; $index < count($names); $index += 2) {
                        $this->enqueue($names->items[$index], $node->domain === WtPdfDeprecatedDomain::EmbeddedNameTree ? WtPdfDeprecatedDomain::FileSpec : WtPdfDeprecatedDomain::Action, $node, '/Names[' . $index . ']');
                    }
                }
                break;
            case WtPdfDeprecatedDomain::FileSpec:
                $this->when($has('DOS') || $has('Mac') || $has('Unix'), 'filespec-os-names', $node);
                $this->map($dictionary, 'EF', WtPdfDeprecatedDomain::EmbeddedFile, $node);
                $related = $this->dictionary($dictionary->get('RF'));
                if ($related !== null) {
                    $this->charge(count($related));
                    foreach ($related->entries() as $entry) {
                        $pairs = $this->resolve($entry->value);
                        if ($pairs instanceof ArrayType) {
                            $this->charge(count($pairs));
                            for ($index = 1; $index < count($pairs); $index += 2) {
                                $this->enqueue($pairs->items[$index], WtPdfDeprecatedDomain::EmbeddedFile, $node, '/RF/' . $entry->key->value . '[' . $index . ']');
                            }
                        }
                    }
                }
                break;
            case WtPdfDeprecatedDomain::EmbeddedFile:
                $edge('Params', WtPdfDeprecatedDomain::EmbeddedParams);
                if (in_array($name('Subtype'), ['application/vnd.fdf', 'application/vnd.adobe.fdf'], true)) {
                    $this->when($has('EncryptionRevision'), 'encrypted-fdf', $node);
                } elseif ($name('Subtype') === null) {
                    $this->external('embedded-fdf-payload', $node);
                }
                break;
            case WtPdfDeprecatedDomain::EmbeddedParams:
                $this->when($has('Mac'), 'embedded-mac-params', $node);
                break;
            case WtPdfDeprecatedDomain::AcroForm:
                $this->when($has('XFA'), 'forms-xfa', $node);
                $this->when($has('NeedAppearances'), 'forms-need-appearances', $node);
                $edge('DR', WtPdfDeprecatedDomain::Resources);
                $edge('Fields', WtPdfDeprecatedDomain::Field);
                break;
            case WtPdfDeprecatedDomain::Field:
                $this->field($dictionary, $node);
                break;
            case WtPdfDeprecatedDomain::Signature:
                $this->when(in_array($name('SubFilter'), ['adbe.x509.rsa_sha1', 'adbe.pkcs7.sha1'], true), 'signature-legacy-subfilter', $node);
                $this->when($has('R'), 'signature-handler-version', $node);
                $edge('Reference', WtPdfDeprecatedDomain::SignatureReference);
                if ($this->entry($dictionary, 'Contents') instanceof StringType) {
                    $this->external('signature-cms-digests', $node);
                }
                break;
            case WtPdfDeprecatedDomain::SignatureReference:
                $this->when($name('TransformMethod') === 'UR', 'signature-ur', $node);
                $this->when(in_array($name('DigestMethod'), ['MD5', 'SHA1'], true), 'signature-reference-digest', $node);
                break;
            case WtPdfDeprecatedDomain::Seed:
                $digests = $this->entry($dictionary, 'DigestMethod');
                if ($digests instanceof ArrayType) {
                    $this->charge(count($digests));
                    foreach ($digests->items as $digest) {
                        $digest = $this->resolve($digest);
                        $this->when($digest instanceof NameType && $digest->value === 'SHA1', 'signature-seed-sha1', $node);
                    }
                }
                $formats = $this->entry($dictionary, 'SubFilter');
                if ($formats instanceof ArrayType) {
                    $this->charge(count($formats));
                    foreach ($formats->items as $format) {
                        $format = $this->resolve($format);
                        $this->when($format instanceof NameType && in_array($format->value, ['adbe.x509.rsa_sha1', 'adbe.pkcs7.sha1'], true), 'signature-legacy-subfilter', $node);
                    }
                }
                break;
            case WtPdfDeprecatedDomain::ViewerPreferences:
                $this->when($has('ViewArea') || $has('ViewClip') || $has('PrintArea') || $has('PrintClip'), 'viewer-prepress', $node);
                break;
            case WtPdfDeprecatedDomain::MarkInfo:
                $this->when($has('Suspects'), 'markinfo-suspects', $node);
                break;
            case WtPdfDeprecatedDomain::Permissions:
                $this->when($has('UR') || $has('UR3'), 'signature-ur', $node);
                foreach (['DocMDP', 'UR', 'UR3'] as $key) {
                    $edge($key, WtPdfDeprecatedDomain::Signature);
                }
                break;
            case WtPdfDeprecatedDomain::Legal:
                $this->when($has('MovieActions') || $has('SoundActions') || $has('ExternalOPIdicts'), 'legal-legacy-counts', $node);
                break;
            case WtPdfDeprecatedDomain::StructureRoot:
                $edge('K', WtPdfDeprecatedDomain::StructureElement);
                $edge('ClassMap', WtPdfDeprecatedDomain::ClassMap);
                break;
            case WtPdfDeprecatedDomain::StructureElement:
                if ($name('Type') !== 'StructElem') {
                    break; // MCR/OBJR and integer MCIDs are content references, not structure elements.
                }
                $this->when($has('R'), 'structure-revisions', $node);
                foreach (['A', 'C'] as $key) {
                    $edge($key, WtPdfDeprecatedDomain::AttributeAssociations);
                }
                $edge('K', WtPdfDeprecatedDomain::StructureElement);
                $edge('AF', WtPdfDeprecatedDomain::FileSpec);
                break;
            case WtPdfDeprecatedDomain::ClassMap:
                foreach ($dictionary->entries() as $entry) {
                    $this->enqueue($entry->value, WtPdfDeprecatedDomain::Attribute, $node, '/' . $entry->key->value);
                }
                break;
            case WtPdfDeprecatedDomain::AttributeAssociations:
            case WtPdfDeprecatedDomain::Attribute:
                $this->when($name('O') === 'PrintField' && $has('checked'), 'printfield-checked', $node);
                break;
            case WtPdfDeprecatedDomain::Trailer:
                $edge('Info', WtPdfDeprecatedDomain::Info);
                $edge('Encrypt', WtPdfDeprecatedDomain::Encryption);
                break;
            case WtPdfDeprecatedDomain::Info:
                foreach ($dictionary->entries() as $entry) {
                    if (!in_array($entry->key->value, ['CreationDate', 'ModDate'], true) && !$this->resolve($entry->value) instanceof NullType) {
                        $this->recommend('info-metadata', $node);
                    }
                }
                break;
            case WtPdfDeprecatedDomain::Encryption:
                $version = $this->entry($dictionary, 'V');
                $this->when($version instanceof IntegerType && $version->integer() < 5, 'encryption-version', $node);
                if ($name('Filter') === 'Standard') {
                    $revision = $this->entry($dictionary, 'R');
                    $this->when($revision instanceof IntegerType && $revision->integer() < 6, 'standard-revision', $node);
                    $permissions = $this->entry($dictionary, 'P');
                    $this->when($permissions instanceof IntegerType && ($permissions->integer() & 0x200) === 0, 'assistive-restriction', $node);
                } elseif ($name('Filter') === 'Adobe.PubSec') {
                    $this->external('publickey-cms-algorithms', $node);
                }
                $this->map($dictionary, 'CF', WtPdfDeprecatedDomain::CryptFilter, $node, ['Identity']);
                break;
            case WtPdfDeprecatedDomain::CryptFilter:
                $this->when(in_array($name('CFM'), ['V2', 'AESV2'], true), 'crypt-filter-method', $node);
                $this->when($has('Length'), 'crypt-filter-length', $node);
                break;
        }
    }

    private function field(DictionaryType $dictionary, WtPdfDeprecatedNode $node): void
    {
        $type = $this->name($dictionary, 'FT') ?? $node->fieldType;
        $child = new WtPdfDeprecatedNode($node->value, $node->domain, $node->path, $node->depth, $type);
        $this->edge($dictionary, 'Kids', WtPdfDeprecatedDomain::Field, $child);
        if ($this->name($dictionary, 'Subtype') === 'Widget') {
            $this->edge($dictionary, 'A', WtPdfDeprecatedDomain::Action, $node);
        }
        $this->edge($dictionary, 'AA', WtPdfDeprecatedDomain::FieldActions, $node);
        if ($type === 'Sig') {
            $this->edge($dictionary, 'V', WtPdfDeprecatedDomain::Signature, $node);
            $this->edge($dictionary, 'SV', WtPdfDeprecatedDomain::Seed, $node);
        }
    }

    private function content(BasicType $value, WtPdfDeprecatedNode $node): void
    {
        if (!$value instanceof StreamObject) {
            return;
        }
        if (!$value->data instanceof ContentStreams) {
            $this->external('raw-content-operators', $node);
            return;
        }
        foreach ($value->data->operators as $operator) {
            if (++$this->operations > $this->limits->maximumOperations) {
                throw new \OverflowException('The selected typed content-operation budget was exceeded.');
            }
            $this->when($operator->symbol() === 'F', 'operator-F', $node);
        }
    }

    private function when(bool $condition, string $feature, WtPdfDeprecatedNode $node): void
    {
        if ($condition) {
            $this->recommend($feature, $node);
        }
    }

    private function recommend(string $identifier, WtPdfDeprecatedNode $node): void
    {
        $key = $identifier . ':' . spl_object_id($this->resolve($node->value));
        if (!isset($this->reported[$key])) {
            $this->reported[$key] = true;
            $this->results[] = $this->catalogue->feature($identifier)->recommendation($node->path);
        }
    }

    private function external(string $identifier, WtPdfDeprecatedNode $node): void
    {
        $key = $identifier . ':' . spl_object_id($this->resolve($node->value));
        if (!isset($this->reported[$key])) {
            $this->reported[$key] = true;
            $feature = $this->catalogue->feature($identifier);
            $this->results[] = new WtPdfFinding('6.2', 10, WtPdfFindingStatus::AdditionalValidationRequired, $identifier . ': ' . $feature->description . ' [' . $node->path . '].');
        }
    }

    private function charge(int $count): void
    {
        $this->edges += $count;
        if ($this->edges > $this->limits->maximumEdges) {
            throw new \OverflowException('The selected contextual edge budget was exceeded.');
        }
    }

    private function enqueue(BasicType $value, WtPdfDeprecatedDomain $domain, WtPdfDeprecatedNode $parent, string $suffix): void
    {
        if (count($this->pending) >= $this->limits->maximumEdges) {
            throw new \OverflowException('The selected pending-edge budget was exceeded.');
        }
        $this->pending[] = new WtPdfDeprecatedNode($value, $domain, $parent->path . $suffix, $parent->depth + 1, $domain === WtPdfDeprecatedDomain::Field ? $parent->fieldType : null);
    }

    private function edge(DictionaryType $dictionary, string $key, WtPdfDeprecatedDomain $domain, WtPdfDeprecatedNode $parent): void
    {
        $value = $dictionary->get($key);
        if ($value !== null) {
            $this->enqueue($value, $domain, $parent, '/' . $key);
        }
    }

    /** @param list<string> $excludedKeys */
    private function map(DictionaryType $dictionary, string $key, WtPdfDeprecatedDomain $domain, WtPdfDeprecatedNode $parent, array $excludedKeys = []): void
    {
        $bucket = $this->dictionary($dictionary->get($key));
        if ($bucket !== null) {
            $this->charge(count($bucket));
            foreach ($bucket->entries() as $entry) {
                if (!in_array($entry->key->value, $excludedKeys, true)) {
                    $this->enqueue($entry->value, $domain, $parent, '/' . $key . '/' . $entry->key->value);
                }
            }
        }
    }

    private function dictionary(?BasicType $value): ?DictionaryType
    {
        $value = $value === null ? null : $this->resolve($value);
        return $value instanceof DictionaryType ? $value : null;
    }

    private function resolve(BasicType $value): BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }

    private function entry(DictionaryType $dictionary, string $key): ?BasicType
    {
        $value = $dictionary->get($key);
        $value = $value === null ? null : $this->resolve($value);
        return $value instanceof NullType ? null : $value;
    }

    private function name(DictionaryType $dictionary, string $key): ?string
    {
        $value = $this->entry($dictionary, $key);
        return $value instanceof NameType ? $value->value : null;
    }
}
