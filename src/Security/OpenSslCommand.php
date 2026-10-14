<?php

declare(strict_types=1);

namespace PDF\Security;

/** A caller-selected local executable, passed an argument vector without invoking a shell. */
final readonly class OpenSslCommand
{
    private string $executable;

    public function __construct(string $executable)
    {
        $resolved = realpath($executable);
        if ($resolved === false || !is_file($resolved) || str_contains($resolved, "\0")) {
            throw new \InvalidArgumentException('Select an existing local OpenSSL executable explicitly.');
        }
        $this->executable = $resolved;
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): void
    {
        $process = proc_open([$this->executable, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the selected OpenSSL provider.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('The selected OpenSSL operation failed.');
        }
    }
}
