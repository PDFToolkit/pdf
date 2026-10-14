<?php

declare(strict_types=1);

namespace PDF\Tests;

use PHPUnit\Framework\TestCase;

final class Iso32000BundleSourcesTest extends TestCase
{
    public function testEverySuppliedOriginalRetainsItsFilenameAndBytes(): void
    {
        $root = dirname(__DIR__);
        $manifest = json_decode(file_get_contents($root . '/resources/iso32000-2-bundle/sources.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://www.pdfa.org/sponsored-standards/', $manifest['provider_url']);
        self::assertCount(11, $manifest['documents']);
        $names = [];
        $pages = 0;
        foreach ($manifest['documents'] as $source) {
            $path = $root . '/' . $source['path'];
            self::assertSame($source['filename'], basename($path));
            self::assertFileExists($path);
            self::assertSame($source['bytes'], filesize($path));
            self::assertSame($source['sha256'], hash_file('sha256', $path));
            self::assertFalse($source['composer_distribution_included']);
            self::assertSame('PDF Association', $source['provider']);
            $names[] = $source['filename'];
            $pages += $source['physical_pdf_pages'];
        }
        self::assertCount(11, array_unique($names));
        self::assertSame(1234, $pages);
    }

    public function testDifferentSuppliedCopiesDoNotReplaceTheExistingEditionSource(): void
    {
        $root = dirname(__DIR__);
        $earlier = $root . '/ISO_32000-2_sponsored_EC3.pdf';
        $new = $root . '/resources/iso32000-2-bundle/documents/ISO_32000-2_sponsored_EC3(1).pdf';
        self::assertSame('90f4b666d46405dd62630a98d514158b4de5304b749523c4081680ac4fe2e230', hash_file('sha256', $earlier));
        self::assertSame('0ab938d855bb142456635fdb60c650b1caecfe3a944583a50da9efad34bbf9f4', hash_file('sha256', $new));
    }
}
