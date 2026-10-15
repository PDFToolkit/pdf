<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentGraphContext;
use PDF\Document\Files\FileSpecificationRules;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringType;

/** ISO1 12.6.4.3–4 pp427–429; ISO2 pp527–530, Tables203–205. No implicit remote I/O. */
final readonly class RemoteActionGraphValidation
{
    public function __construct(private NavigationLimits $limits = new NavigationLimits())
    {
    }

    public function validate(DictionaryType $action, string $kind, DocumentGraphContext $graph, ?EffectivePdfVersion $version): void
    {
        if (!in_array($kind, ['GoToR', 'GoToE'], true)) {
            return;
        }
        $version?->require($kind === 'GoToR' ? PdfVersion::PDF11 : PdfVersion::PDF16, $kind . ' action');
        $file = $graph->entry($action, 'F');
        if ($file === null && $kind === 'GoToR') {
            throw new \InvalidArgumentException('A GoToR action requires its F file specification.');
        }
        if ($file !== null) {
            $specification = $graph->resolve($file);
            if (!$specification instanceof StringType) {
                $scope = $graph->forDirectValue($file);
                $dictionary = $specification instanceof DictionaryType ? $scope->dictionary($file) : null;
                if ($dictionary === null) {
                    throw new \InvalidArgumentException('A remote action F is a file-specification string or dictionary.');
                }
                (new FileSpecificationRules())->validate($dictionary, $scope);
            }
        }
        $destination = $this->value($action, 'D', $graph);
        if ($destination instanceof ArrayType) {
            $items = array_map($graph->resolve(...), $destination->items);
            if (!(($items[0] ?? null) instanceof IntegerType) || $items[0]->value < 0 || !(($items[1] ?? null) instanceof NameType)) {
                throw new \InvalidArgumentException('A remote explicit destination starts with a zero-based page number and a view name, not a local page reference.');
            }
            new DestinationView($items[1], ...array_slice($items, 2));
        } elseif (!$destination instanceof StringType && !$destination instanceof NameType) {
            throw new \InvalidArgumentException('A remote action requires a named or explicit D destination.');
        }
        $window = $this->value($action, 'NewWindow', $graph);
        if ($window !== null) {
            if (!$window instanceof BooleanType) {
                throw new \InvalidArgumentException('Remote action NewWindow is boolean.');
            }
            $version?->require($kind === 'GoToR' ? PdfVersion::PDF12 : PdfVersion::PDF16, 'Remote action NewWindow');
        }
        if ($kind === 'GoToE') {
            $target = $graph->entry($action, 'T');
            if ($file === null && $target === null) {
                throw new \InvalidArgumentException('GoToE requires a T target path when its F root file is absent.');
            }
            if ($target !== null) {
                $this->targetPath($target, $graph);
            }
        }
    }

    private function targetPath(BasicType $reference, DocumentGraphContext $graph): void
    {
        $seen = [];
        while (true) {
            $scope = $graph->forDirectValue($reference);
            $target = $scope->resolve($reference) instanceof DictionaryType ? $scope->dictionary($reference) : null;
            if ($target === null) {
                throw new \InvalidArgumentException('Each embedded target path element is a dictionary.');
            }
            $identity = $scope->identifier($reference);
            $key = $identity === null ? 'direct:' . spl_object_id($target) : (string) $identity;
            if (isset($seen[$key]) || count($seen) >= $this->limits->maxDepth || count($seen) >= $this->limits->maxNodes) {
                throw new \InvalidArgumentException('An embedded target path cannot cycle or exceed its explicit construction budget.');
            }
            $seen[$key] = true;
            $relationship = $this->value($target, 'R', $graph);
            if (!$relationship instanceof NameType || !in_array($relationship->value, ['P', 'C'], true)) {
                throw new \InvalidArgumentException('An embedded target R identifies a parent P or child C document.');
            }
            $name = $this->value($target, 'N', $graph);
            $page = $this->value($target, 'P', $graph);
            $annotation = $this->value($target, 'A', $graph);
            if ($relationship->value === 'P') {
                if ($name !== null || $page !== null || $annotation !== null) {
                    throw new \InvalidArgumentException('A parent-document target has no child N, P or A selector.');
                }
            } elseif ($name !== null) {
                if (!$name instanceof StringType || $page !== null || $annotation !== null) {
                    throw new \InvalidArgumentException('An EmbeddedFiles child target uses its N byte string without attachment P/A selectors.');
                }
            } elseif (($page instanceof IntegerType && $page->value >= 0) || $page instanceof StringType) {
                if (!(($annotation instanceof IntegerType && $annotation->value >= 0) || $annotation instanceof StringType)) {
                    throw new \InvalidArgumentException('A file-attachment target pairs its page P with an annotation index or NM text string A.');
                }
            } else {
                throw new \InvalidArgumentException('A child-document target requires an EmbeddedFiles N or a file-attachment P/A pair.');
            }
            $next = $graph->entry($target, 'T');
            if ($next === null) {
                return;
            }
            $reference = $next;
        }
    }

    private function value(DictionaryType $dictionary, string $key, DocumentGraphContext $graph): ?BasicType
    {
        $entry = $graph->entry($dictionary, $key);
        return $entry === null ? null : $graph->resolve($entry);
    }
}
