<?php

declare(strict_types=1);

namespace Drove\Laravel\Proof;

use RuntimeException;

final readonly class PinnedGitCheckout
{
    private function __construct(
        private string $root,
        private string $commit,
    ) {}

    public static function fromEnvironment(string $environment, string $commit): self
    {
        $configured = getenv($environment);

        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException($environment.' must identify the pinned read-only checkout.');
        }

        $root = realpath($configured);

        if (! is_string($root) || ! is_dir($root)) {
            throw new RuntimeException($environment.' does not identify a readable checkout.');
        }

        if (is_writable($root)) {
            throw new RuntimeException($environment.' must be mounted read-only.');
        }

        $checkout = new self($root, $commit);
        $checkout->assertExactAndClean();

        return $checkout;
    }

    public function source(string $path, string $blob): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException('Pinned checkout source path is invalid: '.$path.'.');
        }

        $source = @file_get_contents($this->root.'/'.$path);

        if (! is_string($source)) {
            throw new RuntimeException('Pinned checkout source is unavailable: '.$path.'.');
        }

        $actual = sha1('blob '.strlen($source)."\0".$source);

        if (! hash_equals($blob, $actual)) {
            throw new RuntimeException('Pinned checkout git blob drifted: '.$path.'.');
        }

        return $source;
    }

    public function commitTimestamp(): int
    {
        $timestamp = $this->git('show', '-s', '--format=%ct', $this->commit);

        if (preg_match('/\A[1-9][0-9]*\z/D', $timestamp) !== 1) {
            throw new RuntimeException('Pinned checkout commit timestamp is invalid.');
        }

        return (int) $timestamp;
    }

    public function assertExactAndClean(): void
    {
        $head = $this->git('rev-parse', 'HEAD');

        if ($head !== $this->commit) {
            throw new RuntimeException(sprintf(
                'Pinned checkout HEAD drifted (%s != %s).',
                $head,
                $this->commit,
            ));
        }

        $status = $this->git('status', '--porcelain=v1', '--untracked-files=all');

        if ($status !== '') {
            throw new RuntimeException('Pinned checkout is dirty: '.$status);
        }
    }

    private function git(string ...$arguments): string
    {
        /** @var non-empty-list<string> $command */
        $command = [
            'git',
            '--no-optional-locks',
            '-c',
            'safe.directory='.$this->root,
            '-c',
            'core.autocrlf=true',
            '-C',
            $this->root,
            ...$arguments,
        ];
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start git for pinned-checkout verification.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! is_string($stdout) || ! is_string($stderr)) {
            throw new RuntimeException(sprintf(
                'Pinned-checkout git command failed (%d): %s',
                $exitCode,
                is_string($stderr) ? trim($stderr) : 'unreadable stderr',
            ));
        }

        return rtrim($stdout, "\r\n");
    }
}
