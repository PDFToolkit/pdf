<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\TestCase;

/** Adobe PDF1.7 errata physical page6; ISO1 physical page86; ISO2 physical page120. */
final class PageContentExtentTest extends TestCase
{
    public function testAnEmptyPageOmitsItsContentEntry(): void
    {
        $page = new PageObject($this->box(), new ResourceDictionary());
        (new PageTreeNode())->addKid($page);
        self::assertFalse($page->has('Contents'));
    }

    public function testAnExplicitEmptyStreamRemainsLegal(): void
    {
        $stream = new StreamObject('');
        $page = new PageObject($this->box(), new ResourceDictionary(), new ArrayType(new IndirectReference($stream)));
        (new PageTreeNode())->addKid($page);
        self::assertCount(1, $page->get('Contents'));
    }

    public function testWriterRejectsAnEmptyContentsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty Contents array');
        new PageObject(contents: new ArrayType());
    }

    private function box(): Rectangle
    {
        return new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100));
    }
}
