<?php

declare(strict_types=1);

use PDF\Document\Destinations\Fit;
use PDF\Document\OutlineDictionary;
use PDF\Document\OutlineItem;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\BasicType;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final readonly class PageOutlineFixtures
{
    public function emit(): void
    {
        $cases = [];
        foreach (['1.7', '2.0'] as $edition) {
            foreach (CrossReferenceFormat::cases() as $format) {
                $box = new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(100), new IntegerType(100));
                $page = new PageObject(
                    $box,
                    new ResourceDictionary(),
                    null,
                    $this->e('Rotate', new IntegerType(-90)),
                    $this->e('UserUnit', new NumericType(1)),
                    $this->e('Tabs', new NameType($edition === '2.0' ? 'A' : 'R')),
                    $this->e('TrimBox', new ArrayType(new IntegerType(95), new IntegerType(95), new IntegerType(5), new IntegerType(5))),
                );
                $closed = (new OutlineItem(new TextString('Closed'), new Fit($page), false))->addItem(new OutlineItem(new TextString('Hidden'), new Fit($page)));
                $first = (new OutlineItem(new TextString('Open'), new Fit($page), true, $this->e('F', new IntegerType(3)), $this->e('C', new ArrayType(new NumericType(0.25), new IntegerType(0), new IntegerType(1)))))
                    ->addItem(new OutlineItem(new TextString('Child'), new Fit($page)))->addItem($closed);
                $root = (new OutlineDictionary())->addItem($first)->addItem(new OutlineItem(new TextString('Last'), new Fit($page)));
                $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $this->e('Outlines', new IndirectReference($root)));
                $initial = (new FileStructure(new FileHeader($edition), new FileBody($catalog), crossReferenceFormat: $format))->toRevision();
                $changes = [];
                foreach ([[$first, 'Count', new IntegerType(-2)], [$root, 'Count', new IntegerType(2)], [$page, 'UserUnit', new NumericType(2.5)]] as [$owner, $key, $value]) {
                    $record = $initial->binding($owner);
                    $dictionary = ($record->dictionary ?? throw new LogicException('The fixture has dictionary snapshots.'))->with($this->e($key, $value));
                    $changes[] = new IndirectObject($dictionary, $record->identifier);
                }
                // A retained outline must use the emitted graph, never this changed historical builder.
                $first->addItem(new OutlineItem(new TextString('Not emitted')));
                $updated = (new IncrementalUpdate($initial, new FileBody(...$changes)))->toRevision();
                $cases[] = ['edition' => $edition, 'format' => $format->name, 'initial' => base64_encode($initial->bytes), 'updated' => base64_encode($updated->bytes)];
            }
        }
        echo json_encode(['cases' => $cases], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    }

    private function e(string $key, BasicType $value): Entry
    {
        return new Entry(new NameType($key), $value);
    }
}

(new PageOutlineFixtures())->emit();
