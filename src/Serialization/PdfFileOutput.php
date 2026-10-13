<?php

declare(strict_types=1);

namespace PDF\Serialization;

/** Filesystem output policy shared by complete files and frozen revisions. */
final class PdfFileOutput
{
    public function validatePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1) {
            throw new \InvalidArgumentException('The PDF output must be a non-empty filesystem path without a stream wrapper.');
        }
    }

    public function write(string $path, string $bytes): void
    {
        $this->validatePath($path);
        $written = @file_put_contents($path, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            throw new \RuntimeException('Unable to write the complete PDF.');
        }
    }
}
