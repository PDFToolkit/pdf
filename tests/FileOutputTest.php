<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\FileStructure;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileOutputTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-output-' . bin2hex(random_bytes(16));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (scandir($this->directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->directory . DIRECTORY_SEPARATOR . $name);
            }
        }
        rmdir($this->directory);
    }

    public function testWritesExactBytesAndOverwritesATrustedLocalPathWithSpaces(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'output with spaces.pdf';
        file_put_contents($path, 'previous document');
        $file = $this->document();

        $file->writeTo($path);

        self::assertSame($file->toPdf(), file_get_contents($path));
    }

    public function testInvalidDocumentPreservesAnExistingDestination(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'existing.pdf';
        file_put_contents($path, 'destination must remain unchanged');
        $file = new FileStructure(new FileHeader(), new FileBody());

        try {
            $file->writeTo($path);
            self::fail('The document without a catalogue must be rejected.');
        } catch (\LogicException) {
            self::assertSame('destination must remain unchanged', file_get_contents($path));
        }
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPathsBeforeSerializingTheDocument(string $path): void
    {
        $file = new FileStructure(new FileHeader(), new FileBody());

        $this->expectException(\InvalidArgumentException::class);
        $file->writeTo($path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'empty path' => [''];
        yield 'NUL in path' => ["output\0.pdf"];
        yield 'file wrapper' => ['file:///pdf-output-test.pdf'];
        yield 'PHP wrapper' => ['php://memory'];
        yield 'PHP filter wrapper' => ['php://filter/write=convert.base64-encode/resource=php://memory'];
        yield 'HTTPS wrapper' => ['https://example.invalid/pdf-output-test.pdf'];
        yield 'custom wrapper' => ['custom://pdf-output-test.pdf'];
        yield 'mixed case scheme with punctuation' => ['CuStOm+v1.2://pdf-output-test.pdf'];
    }

    public function testMissingParentThrowsAnExceptionWithoutDisclosingThePath(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'document.pdf';

        try {
            $this->document()->writeTo($path);
            self::fail('Writing into a missing parent directory must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString($this->directory, $exception->getMessage());
            self::assertFileDoesNotExist($path);
        }
    }

    private function document(): FileStructure
    {
        $catalog = new DocumentCatalog(new PageTreeNode());

        return new FileStructure(new FileHeader(), new FileBody($catalog, new StreamObject("binary\0bytes\xff")));
    }
}
