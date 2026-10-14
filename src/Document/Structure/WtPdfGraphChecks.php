<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

use PDF\Document\Fonts\CMaps\PredefinedCMap;
use PDF\Document\Fonts\ToUnicodeCMap;
use PDF\Document\Metadata\Declarations\Declarations;
use PDF\Document\Metadata\MetadataStream;
use PDF\Document\Metadata\XmpLanguageAlternative;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\TextStringDecoder;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringType;

/** Bounded native-object inspection; font program parsing stays in the independent font modules. */
final readonly class WtPdfGraphChecks
{
    public function __construct(private int $maximumObjects = 100000, private int $maximumUnicodeMappings = 1000000)
    {
        if ($maximumObjects < 1 || $maximumUnicodeMappings < 1) {
            throw new \InvalidArgumentException('WTPDF graph and mapping audits require positive explicit limits.');
        }
    }

    /** @return list<WtPdfFinding> */
    public function inspect(DictionaryType $catalog, ?StructureTreeRoot $root, WtPdfConformanceLevel $level): array
    {
        $findings = [];
        $fail = static function (string $clause, int $page, string $message) use (&$findings): void {
            $findings[] = new WtPdfFinding($clause, $page, WtPdfFindingStatus::Failed, $message);
        };
        $metadata = $this->dereference($catalog->get('Metadata'));
        $hasTitle = false;
        $hasDeclaration = false;
        if ($metadata instanceof MetadataStream) {
            foreach ($metadata->packet->properties as $property) {
                if ($property instanceof XmpLanguageAlternative && $property->expandedName() === '{http://purl.org/dc/elements/1.1/}title') {
                    $hasTitle = array_filter($property->values, static fn ($text): bool => trim($text->text->value) !== '') !== [];
                }
                if ($property instanceof Declarations) {
                    foreach ($property->declarations as $declaration) {
                        $hasDeclaration = $hasDeclaration || $declaration->conformsTo->value === $level->declarationIdentifier();
                    }
                }
            }
        } elseif ($metadata instanceof StreamObject) {
            $findings[] = new WtPdfFinding('8.11.1', 47, WtPdfFindingStatus::AdditionalValidationRequired, 'Validate the generic XMP stream with an independent XML/XMP validator; this audit inspects typed MetadataStream packets.');
        }
        if (!$hasTitle && (!$metadata instanceof StreamObject || $metadata instanceof MetadataStream)) {
            $fail('8.11.1', 47, 'Document metadata requires a nonempty dc:title language alternative.');
        }
        if (!$hasDeclaration && (!$metadata instanceof StreamObject || $metadata instanceof MetadataStream)) {
            $fail($level === WtPdfConformanceLevel::Reuse ? '6.1.2' : '6.1.3', $level === WtPdfConformanceLevel::Reuse ? 9 : 10, 'The selected WTPDF declaration is absent from typed document metadata.');
        }
        $preferences = $this->dictionary($catalog->get('ViewerPreferences'));
        $title = $preferences?->get('DisplayDocTitle');
        if ($level === WtPdfConformanceLevel::Accessibility && (!$title instanceof BooleanType || !$title->value)) {
            $fail('8.11.2', 48, 'Accessibility requires ViewerPreferences.DisplayDocTitle=true.');
        }
        $markInfo = $this->dictionary($catalog->get('MarkInfo'));
        $marked = $markInfo?->get('Marked');
        if (!$marked instanceof BooleanType || !$marked->value) {
            $fail('8.2', 14, 'WTPDF requires a tagged document with MarkInfo.Marked=true.');
        }
        $form = $this->dictionary($catalog->get('AcroForm'));
        if ($form?->has('XFA')) {
            $fail('8.10.1', 44, 'XFA forms are forbidden.');
        }
        $optional = $this->dictionary($catalog->get('OCProperties'));
        if ($level === WtPdfConformanceLevel::Accessibility && $optional !== null) {
            $configs = $this->dereference($optional->get('Configs'));
            $configurations = $configs instanceof ArrayType ? $configs->items : [];
            $default = $optional->get('D');
            if ($default !== null) {
                $configurations[] = $default;
            }
            foreach ($configurations as $configuration) {
                $dictionary = $this->dictionary($configuration);
                if ($dictionary === null || $dictionary->has('AS')) {
                    $fail('8.7', 36, 'Optional-content configurations must be dictionaries and must not contain AS.');
                }
                $name = $dictionary?->get('Name');
                if ($configs instanceof ArrayType && $configs->items !== [] && (!$name instanceof StringType || $name->value === '')) {
                    $fail('8.7', 36, 'Every optional-content configuration, including the default, requires a nonempty Name when Configs is nonempty.');
                }
            }
        }
        $parents = new \SplObjectStorage();
        if ($root !== null) {
            foreach ($root->index()->elements as $element) {
                foreach ($element->kids() as $kid) {
                    if ($kid instanceof ObjectReference) {
                        $parents[$kid->object] = $element;
                    }
                    if ($kid instanceof MarkedContentReference && $kid->container->streamOwner instanceof DictionaryType && $kid->container->streamOwner->get('Subtype') instanceof NameType) {
                        $fail('8.9.2.1', 38, 'Annotation appearance substructure must not be represented by marked-content references.');
                    }
                }
            }
        }
        $resolver = $root === null ? null : new NamespaceRoleResolver($root->namespaces, $root->roleMap);
        $seen = new \SplObjectStorage();
        $queue = [$catalog];
        $hasFonts = false;
        $decoder = new TextStringDecoder(PdfVersion::PDF20);
        while ($queue !== []) {
            $object = array_pop($queue);
            if ($seen->offsetExists($object)) {
                continue;
            }
            if (count($seen) >= $this->maximumObjects) {
                throw new \OverflowException('WTPDF graph inspection exceeds its explicit object budget.');
            }
            $seen->offsetSet($object);
            if ($object instanceof IndirectReference) {
                $queue[] = $object->target();
                continue;
            }
            if ($object instanceof ArrayType) {
                array_push($queue, ...$object->items);
                continue;
            }
            if ($object instanceof TextString && $this->privateUse($object->text)) {
                $fail('8.6', 36, 'A human-readable text string contains a Unicode private-use character.');
            }
            if ($object instanceof ToUnicodeCMap) {
                $used = 0;
                foreach ($object->definition->mappings->items as $mapping) {
                    $range = $mapping->source();
                    $last = $range->distance($this->maximumUnicodeMappings);
                    if ($last >= $this->maximumUnicodeMappings - $used) {
                        throw new \OverflowException('WTPDF Unicode inspection exceeds its explicit mapping budget.');
                    }
                    for ($offset = 0; $offset <= $last; ++$offset) {
                        $sequence = $mapping->sequence($range->low->add($offset));
                        if ($sequence !== null && preg_match('/[\x{0}\x{FEFF}\x{FFFE}]/u', $sequence->utf8()) === 1) {
                            $fail('8.4.5.8', 35, 'ToUnicode destinations must exclude U+0000, U+FEFF and U+FFFE.');
                        }
                    }
                    $used += $last + 1;
                }
            }
            $dictionary = $object instanceof StreamObject ? $object->dictionary() : ($object instanceof DictionaryType ? $object : null);
            if ($dictionary === null) {
                continue;
            }
            $type = $dictionary->get('Type');
            $subtype = $dictionary->get('Subtype');
            if ($type instanceof NameType && $type->value === 'Page') {
                $annotations = $this->dereference($dictionary->get('Annots'));
                if ($annotations instanceof ArrayType && $annotations->items !== []) {
                    $tabs = $dictionary->get('Tabs');
                    if (!$tabs instanceof NameType || !in_array($tabs->value, ['A', 'W', 'S'], true)) {
                        $fail('8.9.3.3', 43, 'Every page with annotations requires Tabs=A, W or S.');
                    }
                    foreach ($annotations->items as $annotation) {
                        $target = $this->dereference($annotation);
                        $annotationDictionary = $this->dictionary($target);
                        if ($annotationDictionary !== null) {
                            $parent = $target !== null && $parents->offsetExists($target) ? $parents[$target] : null;
                            $role = $parent === null ? null : $resolver?->resolve(new StructureRole($parent->type, $parent->namespace));
                            $this->annotation($annotationDictionary, $parent, $role?->type->value, $level, $fail, $decoder);
                        }
                    }
                }
            }
            if ($type instanceof NameType && $type->value === 'Font') {
                $hasFonts = true;
                $this->font($dictionary, $fail, $findings);
            }
            foreach ($dictionary->entries() as $entry) {
                $value = $entry->value;
                if (in_array($entry->key->value, ['Alt', 'ActualText', 'Contents', 'TU', 'Title', 'Desc'], true) && $value instanceof StringType && $this->privateUse($decoder->decode($value))) {
                    $fail('8.6', 36, 'A human-readable ' . $entry->key->value . ' string contains Unicode private-use characters.');
                }
                if ($entry->key->value === 'EmbeddedFiles') {
                    $tree = $this->dictionary($value);
                    if ($tree !== null) {
                        $this->embeddedDescriptions($tree, $fail);
                    }
                }
                array_push($queue, $value);
            }
        }
        if ($hasFonts) {
            $findings[] = new WtPdfFinding('8.4.5.2–8.4.5.9', 32, WtPdfFindingStatus::AdditionalValidationRequired, 'Validate used font programs, embedding rights, rendered-glyph coverage, horizontal/vertical metric agreement, character encodings, complete Unicode mapping and absence of .notdef with the font modules and an independent PDF validator.');
        }
        return $findings;
    }

    /** @param callable(string, int, string): void $fail */
    private function annotation(DictionaryType $annotation, ?StructureElement $parent, ?string $role, WtPdfConformanceLevel $level, callable $fail, TextStringDecoder $decoder): void
    {
        $subtype = $annotation->get('Subtype');
        $name = $subtype instanceof NameType ? $subtype->value : '';
        if (in_array($name, ['Sound', 'Movie', 'TrapNet'], true)) {
            $fail('8.9.1', 37, 'Deprecated annotation type ' . $name . ' is forbidden.');
        }
        $flags = $annotation->get('F');
        $flags = $flags instanceof IntegerType ? $flags->integer() : 0;
        $artifact = ($flags & 1) !== 0 || (($flags & 32) !== 0 && ($flags & 256) === 0) || $name === 'PrinterMark';
        $rectangle = $annotation->get('Rect');
        if ($name === 'Widget' && $rectangle instanceof ArrayType && count($rectangle->items) === 4) {
            $coordinates = array_map(static fn ($number): ?float => $number instanceof \PDF\Syntax\Object\NumericType ? (float) $number->value : null, $rectangle->items);
            $artifact = $artifact || (!in_array(null, $coordinates, true) && $coordinates[0] === $coordinates[2] && $coordinates[1] === $coordinates[3]);
        }
        if ($name === 'Popup' && $parent !== null) {
            $fail('8.9.2.4.9', 40, 'Popup annotations must not be present in the structure tree.');
        }
        if ($artifact && $parent !== null && $role !== 'Artifact') {
            $fail('8.9.2.2', 38, 'An invisible/nonviewable annotation or printer mark must be omitted from structure or enclosed in Artifact.');
        }
        if (!$artifact && $parent === null && $name !== 'Popup') {
            // A visible annotation can intentionally be an artifact. Its semantic classification requires review.
            // No synthetic tagging or false categorical failure is introduced here.
        }
        if ($parent !== null && $role !== 'Artifact') {
            if ($name === 'Widget' && $role !== 'Form') {
                $fail('8.10.1', 44, 'A non-artifact widget must be enclosed by Form.');
            }
            if ($name === 'Link' && !in_array($role, ['Link', 'Reference'], true)) {
                $fail('8.2.5.20', 22, 'A link annotation must be enclosed by Link or Reference.');
            }
            if (in_array($name, ['Text', 'FreeText', 'Line', 'Square', 'Circle', 'Polygon', 'PolyLine', 'Highlight', 'Underline', 'Squiggly', 'StrikeOut', 'Caret', 'Stamp', 'Ink', 'FileAttachment', 'Redact', 'Watermark', 'Projection'], true) && $role !== 'Annot') {
                $fail('8.9.2.3', 39, 'A markup annotation must be enclosed by Annot.');
            }
            $contents = $annotation->get('Contents');
            $alt = $parent->get('Alt');
            if ($contents instanceof StringType && $alt instanceof StringType && $decoder->decode($contents) !== $decoder->decode($alt)) {
                $fail('8.9.4.2', 43, 'An annotation Contents description and the enclosing element Alt must be identical.');
            }
            if ($name === 'Widget') {
                $widgets = array_filter($parent->kids(), static fn ($kid): bool => $kid instanceof ObjectReference && ($kid->object instanceof DictionaryType ? $kid->object : $kid->object->dictionary())->get('Subtype') instanceof NameType && ($kid->object instanceof DictionaryType ? $kid->object : $kid->object->dictionary())->get('Subtype')->value === 'Widget');
                if (count($widgets) > 1) {
                    $fail('8.10.1', 44, 'A Form structure element may enclose at most one widget annotation.');
                }
                if ($annotation->has('AA') && !$contents instanceof StringType) {
                    $fail('8.10.2.3', 45, 'A widget with additional actions requires a Contents description.');
                }
            }
        }
        $contents = $annotation->get('Contents');
        if (($name === 'Screen' || ($level === WtPdfConformanceLevel::Accessibility && in_array($name, ['Ink', '3D', 'RichMedia'], true))) && (!$contents instanceof StringType || $contents->value === '')) {
            $fail('8.9.2.4', 40, 'Annotation type ' . $name . ' requires a nonempty Contents description.');
        }
        $file = $this->dictionary($annotation->get('FS'));
        if ($name === 'FileAttachment' && $file !== null && !$file->get('AFRelationship') instanceof NameType) {
            $fail('8.9.2.4.10', 41, 'A file-attachment specification requires AFRelationship.');
        }
    }

    /**
     * @param callable(string, int, string): void $fail
     * @param list<WtPdfFinding> $findings
     */
    private function font(DictionaryType $font, callable $fail, array &$findings): void
    {
        $subtype = $font->get('Subtype');
        $name = $subtype instanceof NameType ? $subtype->value : '';
        $descriptor = $this->dictionary($font->get('FontDescriptor'));
        if ($name === 'CIDFontType2' && $this->dereference($descriptor?->get('FontFile2')) instanceof StreamObject) {
            $map = $this->dereference($font->get('CIDToGIDMap'));
            if (!$map instanceof StreamObject && !($map instanceof NameType && $map->value === 'Identity')) {
                $fail('8.4.5.3.2', 33, 'An embedded Type 2 CIDFont requires a stream or Identity CIDToGIDMap.');
            }
        }
        if ($name !== 'Type0') {
            return;
        }
        $encoding = $this->dereference($font->get('Encoding'));
        if ($encoding instanceof NameType) {
            if (PredefinedCMap::tryFrom($encoding->value) === null) {
                $fail('8.4.5.4', 33, 'A nonstandard encoding CMap must be embedded.');
            }
            return;
        }
        if (!$encoding instanceof StreamObject) {
            $fail('8.4.5.3.1', 33, 'A Type 0 encoding must be a predefined CMap or embedded stream.');
            return;
        }
        $descendants = $this->dereference($font->get('DescendantFonts'));
        $descendant = $descendants instanceof ArrayType ? $this->dictionary($descendants->items[0] ?? null) : null;
        $fontInfo = $this->dictionary($descendant?->get('CIDSystemInfo'));
        $mapInfo = $this->dictionary($encoding->dictionary()->get('CIDSystemInfo'));
        foreach (['Registry', 'Ordering'] as $key) {
            $a = $fontInfo?->get($key);
            $b = $mapInfo?->get($key);
            if (!$a instanceof StringType || !$b instanceof StringType || $a->value !== $b->value) {
                $fail('8.4.5.3.1', 33, 'CIDFont and encoding CMap Registry/Ordering must match.');
            }
        }
        $a = $fontInfo?->get('Supplement');
        $b = $mapInfo?->get('Supplement');
        if (!$a instanceof IntegerType || !$b instanceof IntegerType || $a->integer() < $b->integer()) {
            $fail('8.4.5.3.1', 33, 'CIDFont Supplement must cover the encoding CMap Supplement.');
        }
        $base = $this->dereference($encoding->dictionary()->get('UseCMap'));
        if ($base !== null && (!$base instanceof NameType || PredefinedCMap::tryFrom($base->value) === null)) {
            $fail('8.4.5.4', 33, 'A CMap may reference only a standard predefined base CMap.');
        }
        if (!$encoding instanceof \PDF\Document\Fonts\CMaps\EncodingCMap) {
            $findings[] = new WtPdfFinding('8.4.5.4', 33, WtPdfFindingStatus::AdditionalValidationRequired, 'Check dictionary/program WMode agreement in the generic embedded encoding CMap.');
        }
    }

    /** @param callable(string, int, string): void $fail */
    private function embeddedDescriptions(DictionaryType $tree, callable $fail): void
    {
        $queue = [$tree];
        $seen = new \SplObjectStorage();
        while ($queue !== []) {
            $node = array_pop($queue);
            if ($seen->offsetExists($node)) {
                continue;
            }
            $seen->offsetSet($node);
            if (count($seen) > $this->maximumObjects) {
                throw new \OverflowException('The embedded-file name tree exceeds its explicit audit budget.');
            }
            $names = $this->dereference($node->get('Names'));
            if ($names instanceof ArrayType) {
                for ($index = 1; $index < count($names->items); $index += 2) {
                    $specification = $this->dictionary($names->items[$index]);
                    if ($specification !== null && !$specification->get('Desc') instanceof StringType) {
                        $fail('8.14.1', 49, 'Every EmbeddedFiles file specification requires a Desc entry.');
                    }
                }
            }
            $kids = $this->dereference($node->get('Kids'));
            foreach ($kids instanceof ArrayType ? $kids->items : [] as $kid) {
                $dictionary = $this->dictionary($kid);
                if ($dictionary !== null) {
                    $queue[] = $dictionary;
                }
            }
        }
    }

    private function privateUse(string $text): bool
    {
        return preg_match('/[\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}]/u', $text) === 1;
    }

    private function dereference(?BasicType $object): ?BasicType
    {
        return $object instanceof IndirectReference ? $object->target() : $object;
    }

    private function dictionary(?BasicType $object): ?DictionaryType
    {
        $object = $this->dereference($object);
        return $object instanceof DictionaryType ? $object : null;
    }
}
