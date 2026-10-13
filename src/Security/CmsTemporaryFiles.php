<?php

declare(strict_types=1);

namespace PDF\Security;

/** Native PHP CMS APIs require files. Staging is isolated per operation and removed in finally. */
final class CmsTemporaryFiles
{
    /** @var list<string> */
    private array $paths = [];
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $directory ??= sys_get_temp_dir();
        if (str_contains($directory, "\0") || str_contains($directory, '://') || ($resolved = realpath($directory)) === false
            || !is_dir($resolved) || !is_writable($resolved)) {
            throw new \InvalidArgumentException('CMS staging requires an existing, writable local directory.');
        }
        $this->directory = $resolved;
    }

    public function write(#[\SensitiveParameter] string $bytes = ''): string
    {
        $path = tempnam($this->directory, 'pdf-cms-');
        if ($path === false) {
            throw new \RuntimeException('Cannot allocate native CMS staging.');
        }
        $this->paths[] = $path;
        if (realpath(dirname($path)) !== $this->directory || !chmod($path, 0600) || file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Cannot securely stage native CMS data.');
        }
        return $path;
    }

    public function read(string $path): string
    {
        if (!in_array($path, $this->paths, true)) {
            throw new \InvalidArgumentException('CMS staging reads only its own files.');
        }
        $bytes = file_get_contents($path);
        return $bytes === false ? throw new \RuntimeException('Cannot read native CMS output.') : $bytes;
    }

    public function close(): void
    {
        $remaining = [];
        foreach ($this->paths as $path) {
            if (is_file($path) && !unlink($path)) {
                $remaining[] = $path;
            }
        }
        $this->paths = $remaining;
        if ($remaining !== []) {
            throw new \RuntimeException('Cannot remove native CMS staging data.');
        }
    }

    public function __destruct()
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
