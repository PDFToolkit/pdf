<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Specification\Definition\FileSpecificationDictionaryTable44Definition;
use PDF\Specification\Definition\PageLabelDictionaryTable159Definition;
use PDF\Specification\Dictionary\FileSpecificationDictionaryTable44\CIEntry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PHPUnit\Framework\TestCase;

final class SourceInterpretationTest extends TestCase
{
    public function testCollectionItemRequiresAnIndirectReferenceWithoutAnIndefiniteArticle(): void
    {
        $constraint = (new FileSpecificationDictionaryTable44Definition())->entry('CI')->type;
        $constraint->validate(new IndirectReference(new DictionaryType()));
        $entry = new CIEntry(new IndirectReference(new DictionaryType()));
        self::assertSame('CI', $entry->key->value);
        $this->expectException(\InvalidArgumentException::class);
        $constraint->validate(new DictionaryType());
    }

    public function testFVersionQualifierDescribesAnAmendmentWhileThePrintedWordingRemainsAvailable(): void
    {
        $f = (new FileSpecificationDictionaryTable44Definition())->entry('F');
        self::assertNull($f->requirement->introducedIn);
        self::assertStringContainsString('amended with the UF entry for PDF 1.7', $f->description);
        self::assertNotNull($f->correction);
    }

    public function testPageLabelsKeepTheirOwnClauseAcrossAContinuedSourceTable(): void
    {
        self::assertSame('12.4.2', (new PageLabelDictionaryTable159Definition())->source->clause);
    }
}
