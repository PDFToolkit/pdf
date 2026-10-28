<?php

declare(strict_types=1);

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final readonly class ActionContractFixtures
{
    public function emit(): void
    {
        $cases = [];
        foreach (['1.7', '2.0'] as $edition) {
            foreach (CrossReferenceFormat::cases() as $format) {
                $screen = new DictionaryType($this->e('Subtype', new NameType('Screen')), $this->e('Rect', new ArrayType(new IntegerType(0), new IntegerType(0), new IntegerType(0), new IntegerType(0))));
                $screenRef = new IndirectReference($screen);
                $group = new DictionaryType($this->e('Type', new NameType('OCG')), $this->e('Name', new LiteralString('Layer')));
                $groupRef = new IndirectReference($group);
                $properties = new DictionaryType($this->e('OCGs', new ArrayType($groupRef)), $this->e('D', new DictionaryType()));
                $actions = [
                    $this->action('Named', $this->e('N', new NameType('NextPage'))),
                    $this->action('JavaScript', $this->e('JS', new LiteralString('/* explicitly supplied, never executed by the writer */'))),
                    $this->action('Launch', $this->e('Win', new DictionaryType($this->e('F', new LiteralString('example.pdf')), $this->e('O', new LiteralString('open'))))),
                    $this->action('Hide', $this->e('T', new ArrayType($screenRef)), $this->e('H', new BooleanType(false))),
                    $this->action('SetOCGState', $this->e('State', new ArrayType(new NameType('ON'), $groupRef, new NameType('Toggle'), $groupRef))),
                    $this->action('Trans', $this->e('Trans', new DictionaryType($this->e('S', new NameType('Dissolve')), $this->e('D', new IntegerType(2))))),
                    $this->action('SubmitForm', $this->e('F', new DictionaryType($this->e('FS', new NameType('URL')), $this->e('F', new LiteralString('https://example.invalid/submit')))), $this->e('Flags', new IntegerType(4 + 8))),
                    $this->action('ResetForm', $this->e('Flags', new IntegerType(1))),
                    $this->action('ImportData', $this->e('F', new LiteralString('example.fdf'))),
                ];
                $open = $this->action('URI', $this->e('URI', new LiteralString('relative/path')), $this->e('Next', new ArrayType(...array_map(static fn (DictionaryType $action): IndirectReference => new IndirectReference($action), $actions))));
                $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100)), new ResourceDictionary(), null, $this->e('Annots', new ArrayType($screenRef)));
                $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $this->e('OCProperties', $properties), $this->e('OpenAction', new IndirectReference($open)));
                $initial = (new FileStructure(new FileHeader($edition), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
                $openRecord = $initial->binding($open);
                $screenRecord = $initial->binding($screen);
                $rendition = $this->action('Rendition', $this->e('OP', new IntegerType(1)), $this->e('AN', $screenRef), $this->e('Next', $openRecord->dictionary->get('Next')));
                $changed = $openRecord->dictionary->with($this->e('URI', new LiteralString('updated/path')))->with($this->e('Next', new IndirectReference($rendition)));
                $updated = (new IncrementalUpdate($initial, new FileBody(
                    new IndirectObject($changed, $openRecord->identifier),
                    new IndirectObject($screenRecord->dictionary->with($this->e('P', new IndirectReference($page))), $screenRecord->identifier),
                )))->toRevision();
                $cases[] = ['edition' => $edition, 'format' => $format->name, 'initial' => base64_encode($initial->bytes), 'updated' => base64_encode($updated->bytes)];
            }
        }
        echo json_encode(['cases' => $cases], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    }

    private function action(string $kind, Entry ...$entries): DictionaryType
    {
        return new DictionaryType($this->e('S', new NameType($kind)), ...$entries);
    }

    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}

(new ActionContractFixtures())->emit();
