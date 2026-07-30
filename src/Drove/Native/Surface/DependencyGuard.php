<?php

declare(strict_types=1);

namespace Drove\Native\Surface;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

final class DependencyGuard
{
    /** @var list<string> */
    private const array FORBIDDEN_SYMBOLS = [
        'Pest',
        'PHPUnit',
        'Testbench',
        '__destruct',
    ];

    /**
     * The optional class list proves that installed bridge entrypoints may be
     * loaded first without changing the native source boundary. It is accepted
     * as inert class-string data; no bridge contract is imported here.
     *
     * @param  list<class-string>  $loadedEntrypoints
     * @return array{
     *     schema: 1,
     *     native_root: string,
     *     inspected_files: int,
     *     loaded_entrypoints: list<array{class: class-string, file: string}>,
     *     violations: list<array{path: string, line: int, symbol: string}>
     * }
     */
    public function inspect(string $nativeRoot, array $loadedEntrypoints = []): array
    {
        $nativeRoot = realpath($nativeRoot);

        if (! is_string($nativeRoot) || ! is_dir($nativeRoot)) {
            throw new InvalidArgumentException(
                'The native dependency guard requires an existing source directory.',
            );
        }

        $nativeRoot = $this->normalizePath($nativeRoot);
        $files = $this->phpFiles($nativeRoot);
        $loaded = $this->loadedEntrypoints($nativeRoot, $loadedEntrypoints);
        $violations = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (! is_string($source)) {
                throw new RuntimeException(sprintf(
                    'The native dependency guard could not read %s.',
                    $file,
                ));
            }

            foreach (token_get_all($source) as $token) {
                if (! is_array($token)) {
                    continue;
                }
                if (! in_array(
                    $token[0],
                    [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_STRING],
                    true,
                )) {
                    continue;
                }
                foreach (self::FORBIDDEN_SYMBOLS as $forbidden) {
                    if (preg_match(
                        sprintf(
                            '~(?:^|\\\\)%s(?:\\\\|$)~i',
                            preg_quote($forbidden, '~'),
                        ),
                        $token[1],
                    ) !== 1) {
                        continue;
                    }

                    $violations[] = [
                        'path' => $this->relativePath($nativeRoot, $file),
                        'line' => $token[2],
                        'symbol' => $forbidden,
                    ];
                }
            }
        }

        usort($violations, static fn (array $left, array $right): int => [
            $left['path'],
            $left['line'],
            $left['symbol'],
        ] <=> [
            $right['path'],
            $right['line'],
            $right['symbol'],
        ]);

        return [
            'schema' => 1,
            'native_root' => $nativeRoot,
            'inspected_files' => count($files),
            'loaded_entrypoints' => $loaded,
            'violations' => $violations,
        ];
    }

    /**
     * @param  list<class-string>  $loadedEntrypoints
     */
    public function assertIndependent(string $nativeRoot, array $loadedEntrypoints = []): void
    {
        $inspection = $this->inspect($nativeRoot, $loadedEntrypoints);

        if ($inspection['violations'] !== []) {
            $violation = $inspection['violations'][0];

            throw new RuntimeException(sprintf(
                'DROVE_NATIVE_DEPENDENCY_VIOLATION: %s:%d references %s.',
                $violation['path'],
                $violation['line'],
                $violation['symbol'],
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $nativeRoot): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $nativeRoot,
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if ($file->isFile()
                && strtolower((string) $file->getExtension()) === 'php') {
                $files[] = $this->normalizePath($file->getPathname());
            }
        }

        sort($files, SORT_STRING);

        if ($files === []) {
            throw new RuntimeException(
                'The native dependency guard found no PHP source files.',
            );
        }

        return $files;
    }

    /**
     * @param  list<class-string>  $entrypoints
     * @return list<array{class: class-string, file: string}>
     */
    private function loadedEntrypoints(string $nativeRoot, array $entrypoints): array
    {
        $loaded = [];
        $seen = [];

        foreach ($entrypoints as $entrypoint) {
            if (isset($seen[$entrypoint])) {
                throw new InvalidArgumentException(
                    'The native dependency guard requires unique loaded entrypoint class names.',
                );
            }

            $seen[$entrypoint] = true;

            if (! class_exists($entrypoint, false)
                && ! interface_exists($entrypoint, false)) {
                throw new InvalidArgumentException(sprintf(
                    'The native dependency guard entrypoint %s is not loaded.',
                    $entrypoint,
                ));
            }

            $file = new ReflectionClass($entrypoint)->getFileName();

            if (! is_string($file)) {
                throw new InvalidArgumentException(sprintf(
                    'The native dependency guard could not locate entrypoint %s.',
                    $entrypoint,
                ));
            }

            $file = $this->normalizePath($file);

            if ($file === $nativeRoot || str_starts_with($file, $nativeRoot.'/')) {
                throw new RuntimeException(sprintf(
                    'DROVE_NATIVE_BRIDGE_BOUNDARY_VIOLATION: %s is implemented inside the native module.',
                    $entrypoint,
                ));
            }

            $loaded[] = ['class' => $entrypoint, 'file' => $file];
        }

        usort(
            $loaded,
            static fn (array $left, array $right): int => $left['class'] <=> $right['class'],
        );

        return $loaded;
    }

    private function relativePath(string $nativeRoot, string $path): string
    {
        return ltrim(substr($path, strlen($nativeRoot)), '/');
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
