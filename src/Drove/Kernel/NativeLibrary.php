<?php

declare(strict_types=1);

namespace Drove\Kernel;

use RuntimeException;

/**
 * @internal
 */
final class NativeLibrary
{
    public static function resolve(?string $explicit = null): string
    {
        $configured = $explicit ?? (getenv('DROVER_LIBRARY') ?: null);

        if ($configured !== null) {
            return $configured;
        }

        $target = self::target();
        $bundled = self::bundledPath($target);

        if (is_file($bundled)) {
            return $bundled;
        }

        $system = '/usr/local/lib/'.self::filename($target);

        if (is_file($system)) {
            return $system;
        }

        throw new RuntimeException(sprintf(
            'Drover native library for %s is not installed. Run drove-install-native or set DROVER_LIBRARY.',
            $target,
        ));
    }

    public static function target(
        ?string $osFamily = null,
        ?string $machine = null,
        ?string $linuxLibc = null,
    ): string {
        $platform = match ($osFamily ?? PHP_OS_FAMILY) {
            'Linux' => self::linuxPlatform($linuxLibc),
            'Darwin' => 'macos',
            default => throw new RuntimeException(sprintf(
                'Drover does not provide native binaries for %s.',
                $osFamily ?? PHP_OS_FAMILY,
            )),
        };
        $machine ??= php_uname('m');
        $architecture = match (strtolower($machine)) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => throw new RuntimeException(sprintf(
                'Drover does not provide native binaries for %s on %s.',
                $machine,
                $platform,
            )),
        };

        return $platform.'-'.$architecture;
    }

    public static function filename(string $target): string
    {
        return str_starts_with($target, 'macos-') ? 'libdrover.dylib' : 'libdrover.so';
    }

    public static function bundledPath(string $target): string
    {
        return dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR.'native'
            .DIRECTORY_SEPARATOR.'drover'
            .DIRECTORY_SEPARATOR.'prebuilt'
            .DIRECTORY_SEPARATOR.$target
            .DIRECTORY_SEPARATOR.self::filename($target);
    }

    private static function linuxPlatform(?string $libc): string
    {
        $libc ??= self::detectLinuxLibc();

        if (str_contains(strtolower($libc), 'musl')) {
            throw new RuntimeException(
                'Drover Linux binaries require glibc 2.31 or newer; Alpine and other musl systems are unsupported.',
            );
        }

        if (preg_match('/\Aglibc\s+([0-9]+\.[0-9]+)\z/i', trim($libc), $matches) !== 1) {
            throw new RuntimeException(
                'Drover could not verify a supported glibc runtime; use a glibc 2.31+ system or set DROVER_LIBRARY.',
            );
        }

        if (version_compare($matches[1], '2.31', '<')) {
            throw new RuntimeException(sprintf(
                'Drover Linux binaries require glibc 2.31 or newer; detected glibc %s.',
                $matches[1],
            ));
        }

        return 'linux-gnu';
    }

    private static function detectLinuxLibc(): string
    {
        if (is_file('/etc/alpine-release')) {
            return 'musl';
        }

        try {
            $process = proc_open(
                ['getconf', 'GNU_LIBC_VERSION'],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                options: ['bypass_shell' => true],
            );
        } catch (\Throwable) {
            $process = false;
        }

        if (! is_resource($process)) {
            return 'unknown';
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return $status === 0 && is_string($output) ? trim($output) : 'unknown';
    }
}
