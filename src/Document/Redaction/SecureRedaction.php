<?php

declare(strict_types=1);

namespace PDF\Document\Redaction;

use PDF\FileStructure;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operator;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;

/**
 * Destructive application, Adobe redaction addendum physical pages 2–4.
 * Rebuilds a minimal fresh document, intentionally dropping every annotation,
 * structure tree, XFA, metadata, attachment, outline, action and old revision.
 * Text objects and images/Forms are indivisible: conservative excess deletion is explicit.
 */
final readonly class SecureRedaction
{
    public function __construct(private RedactionLimits $limits = new RedactionLimits(), private ?RedactionFontGeometry $fontGeometry = null, private ?RedactionResourceSanitizer $resourceSanitizer = null)
    {
    }

    public function apply(DocumentCatalog $catalog, RedactionPlan $plan, FileHeader $header = new FileHeader('1.7')): RedactedDocument
    {
        $root = $this->value($catalog->get('Pages'));
        if (!$root instanceof PageTreeNode) {
            throw new \InvalidArgumentException('Secure redaction requires an explicit native page-tree graph.');
        }
        $pages = $this->pages($root);
        $requests = [];
        foreach ($plan->pages as $request) {
            if (!in_array($request->page, $pages, true)) {
                throw new \InvalidArgumentException('A redaction plan targets a page outside the source catalog.');
            }
            $requests[spl_object_id($request->page)] = $request;
        }
        $processor = new RedactionContentProcessor($this->limits, new RedactionTextBounds($this->fontGeometry), $this->resourceSanitizer);
        $processor->beginCollection();
        $form = $this->value($catalog->get('AcroForm'));
        $defaultResources = $form instanceof DictionaryType ? $this->value($form->get('DR')) : null;
        $processed = [];
        // First collect the closure of every affected shared image/Form. The second
        // traversal removes those resources even from earlier, otherwise untouched pages.
        foreach ([false, true] as $final) {
            if ($final) {
                $processor->completeTaintedClosure();
                $processor->removed = 0;
            }
            foreach ($pages as $page) {
                $annotations = isset($requests[spl_object_id($page)]) ? $requests[spl_object_id($page)]->annotations : [];
                $regions = [];
                foreach ($annotations as $annotation) {
                    array_push($regions, ...$annotation->regions);
                }
                $resources = $this->value($page->effectiveResources());
                if (!$resources instanceof DictionaryType) {
                    throw new \InvalidArgumentException('A page has no explicit effective resource dictionary.');
                }
                $stream = $processor->process($this->contents($page->get('Contents')), $resources, $regions);
                if ($final) {
                    $entries = [];
                    // Only geometric page presentation survives; never copy original graph-bearing extensions.
                    foreach (['CropBox', 'BleedBox', 'TrimBox', 'ArtBox', 'Rotate', 'UserUnit'] as $key) {
                        $value = $this->inherited($page, $key);
                        if ($value !== null) {
                            if (!$value instanceof NumericType && !$value instanceof ArrayType) {
                                throw new \InvalidArgumentException('A retained page geometry value requires normalized numeric syntax.');
                            }
                            if ($value instanceof ArrayType) {
                                foreach ($value->items as $number) {
                                    if (!$number instanceof NumericType) {
                                        throw new \InvalidArgumentException('A retained page box contains a nonnumeric or graph-bearing value.');
                                    }
                                }
                            }
                            $entries[] = new Entry(new NameType($key), $value);
                        }
                    }
                    // Each page begins in the initial PDF graphics state; isolate its source
                    // state so replacement fills never inherit a source CTM, clip or color.
                    $operators = [new SaveGraphicsState(), ...$stream->content->operators, new RestoreGraphicsState()];
                    $pageResources = $stream->resources;
                    $replacementNumber = 0;
                    foreach ($annotations as $annotation) {
                        array_push($operators, ...$this->mark($annotation));
                        $replacement = $annotation->overlay;
                        if ($replacement === null && $annotation->overlayText !== null) {
                            $replacement = (new RedactionOverlayText($this->limits))->form($annotation, $defaultResources instanceof DictionaryType ? $defaultResources : $resources);
                        }
                        if ($replacement !== null) {
                            $xobjects = $this->value($pageResources->get('XObject'));
                            $xobjects = $xobjects instanceof DictionaryType ? $xobjects : new DictionaryType();
                            do {
                                $name = new NameType('RedactionReplacement' . ++$replacementNumber);
                            } while ($xobjects->has($name));
                            $wrapper = new \PDF\Document\ResourceDictionary(new Entry(new NameType('XObject'), new DictionaryType(new Entry($name, new IndirectReference($replacement)))));
                            $normalized = $processor->process(new ContentStreams(new InvokeXObject($name)), $wrapper, []);
                            $normalizedObjects = $this->value($normalized->resources->get('XObject'));
                            $object = $normalizedObjects instanceof DictionaryType ? $normalizedObjects->get($name) : null;
                            if ($object === null) {
                                throw new \InvalidArgumentException('An applied redaction replacement must not reuse an affected original resource.');
                            }
                            $pageResources = $pageResources->with(new Entry(new NameType('XObject'), $xobjects->with(new Entry($name, $object))));
                            $matrix = $this->value($replacement->dictionary()->get('Matrix'));
                            $originX = $originY = 0.0;
                            if ($matrix !== null) {
                                if (!$matrix instanceof ArrayType || count($matrix) !== 6 || !$matrix->items[4] instanceof NumericType || !$matrix->items[5] instanceof NumericType) {
                                    throw new \InvalidArgumentException('A replacement Form has a normalized six-number Matrix.');
                                }
                                $originX = (float) $matrix->items[4]->value;
                                $originY = (float) $matrix->items[5]->value;
                            }
                            array_push($operators, new SaveGraphicsState(), new ConcatenateMatrix(new Matrix(e: new NumericType($annotation->rectangle->left - $originX), f: new NumericType($annotation->rectangle->bottom - $originY))), new InvokeXObject($name), new RestoreGraphicsState());
                        }
                    }
                    $processed[] = new PageObject($page->effectiveMediaBox(), $pageResources, new StreamObject(new ContentStreams(...$operators)), ...$entries);
                }
            }
        }
        $tree = new PageTreeNode();
        foreach ($processed as $page) {
            $tree->addKid($page);
        }
        $newCatalog = new DocumentCatalog($tree);
        return new RedactedDocument(new FileStructure($header, new FileBody($newCatalog), new IndirectReference($newCatalog)), $processor->removed, count($processor->tainted));
    }

    /** @return list<PageObject> */
    private function pages(PageTreeNode $root): array
    {
        $pages = [];
        $queue = [$root];
        $seen = new \SplObjectStorage();
        while ($queue !== []) {
            $node = array_pop($queue);
            if ($seen->offsetExists($node)) {
                throw new \InvalidArgumentException('A redacted page tree must have unique, acyclic page ownership.');
            }
            $seen->offsetSet($node);
            if (count($seen) > $this->limits->objects) {
                throw new \OverflowException('Redaction pages exceed their explicit object budget.');
            }
            if ($node instanceof PageObject) {
                $pages[] = $node;
            } else {
                array_push($queue, ...array_reverse($node->kids()));
            }
        }
        return $pages;
    }

    private function contents(?BasicType $contents): ContentStreams
    {
        $contents = $this->value($contents);
        $streams = $contents instanceof ArrayType ? $contents->items : ($contents === null ? [] : [$contents]);
        $operators = [];
        foreach ($streams as $stream) {
            $stream = $this->value($stream);
            if (!$stream instanceof StreamObject || !$stream->data instanceof ContentStreams) {
                throw new \InvalidArgumentException('Secure redaction requires normalized typed page content, never an opaque byte stream.');
            }
            array_push($operators, ...$stream->data->operators);
        }
        return new ContentStreams(...$operators);
    }

    /** @return list<Operator> */
    private function mark(RedactionAnnotation $annotation): array
    {
        if ($annotation->interior === null) {
            return []; // Absence of IC is transparent, not an implicit black rectangle.
        }
        $operators = [new SaveGraphicsState(), new SetNonstrokingRGBColor(...array_map(static fn (float $value): NumericType => new NumericType($value), $annotation->interior))];
        foreach ($annotation->regions as $region) {
            $operators[] = new AppendRectangle(new NumericType($region->left), new NumericType($region->bottom), new NumericType($region->right - $region->left), new NumericType($region->top - $region->bottom));
            $operators[] = new FillPathNonzero();
        }
        $operators[] = new RestoreGraphicsState();
        return $operators;
    }

    private function inherited(PageObject $page, string $name): ?BasicType
    {
        $value = $this->value($page->get($name));
        if ($value !== null || !in_array($name, ['Rotate', 'CropBox'], true)) {
            return $value;
        }
        for ($node = $page->parent(); $node !== null; $node = $node->parent()) {
            $value = $this->value($node->get($name));
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function value(?BasicType $value): ?BasicType
    {
        return $value instanceof IndirectReference ? $value->target() : $value;
    }
}
