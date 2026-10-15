<?php

declare(strict_types=1);

namespace PDF\Tests;

use PHPUnit\Framework\TestCase;

final class AdobeErrataReviewTest extends TestCase
{
    public function testAllErrataContentPagesHaveExplicitSourceLinkedDispositions(): void
    {
        $root = dirname(__DIR__);
        $review = json_decode(file_get_contents($root . '/resources/adobe-references/errata-review.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($review['source_sha256'], hash_file('sha256', $root . '/' . $review['source_path']));
        self::assertSame(18, $review['physical_pdf_pages']);
        self::assertCount(65, $review['corrections']);
        $pages = [];
        $ids = [];
        foreach ($review['corrections'] as $correction) {
            self::assertArrayNotHasKey($correction['id'], $ids);
            $ids[$correction['id']] = true;
            $pages[$correction['physical_pdf_page']] = true;
            self::assertNotEmpty($correction['topic']);
            self::assertNotEmpty($correction['disposition']);
            self::assertNotEmpty($correction['adobe_reference_printed_pages']);
            foreach ([...$correction['implementation'], ...$correction['validation']] as $path) {
                self::assertFileExists($root . '/' . $path);
            }
            if ($correction['disposition'] === 'implemented-native') {
                self::assertNotEmpty($correction['implementation']);
                self::assertNotEmpty($correction['validation']);
            }
        }
        $physicalPages = array_keys($pages);
        sort($physicalPages);
        self::assertSame(range(3, 18), $physicalPages);
        self::assertSame(range(3, 18), $review['reviewed_content_pages']);
    }
}
