<?php

declare(strict_types=1);

use Drove\Kernel\NativeLibrary;

require_once dirname(__DIR__, 2).'/src/Drove/Kernel/NativeLibrary.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(NativeLibrary::target('Linux', 'amd64', 'glibc 2.31') === 'linux-gnu-x86_64', 'Linux x86_64 target drifted.');
$assert(NativeLibrary::target('Linux', 'aarch64', 'glibc 2.40') === 'linux-gnu-aarch64', 'Linux ARM64 target drifted.');
$assert(NativeLibrary::target('Darwin', 'x86_64') === 'macos-x86_64', 'macOS x86_64 target drifted.');
$assert(NativeLibrary::target('Darwin', 'arm64') === 'macos-aarch64', 'macOS ARM64 target drifted.');
$assert(NativeLibrary::filename('linux-gnu-x86_64') === 'libdrover.so', 'Linux library name drifted.');
$assert(NativeLibrary::filename('macos-aarch64') === 'libdrover.dylib', 'macOS library name drifted.');
$assert(NativeLibrary::resolve('/tmp/libdrover.test') === '/tmp/libdrover.test', 'Explicit native override drifted.');

try {
    NativeLibrary::target('Linux', 'x86_64', 'musl');
    throw new RuntimeException('Musl target detection did not fail.');
} catch (RuntimeException $exception) {
    $assert(
        str_contains($exception->getMessage(), 'musl systems are unsupported'),
        'Musl rejection diagnostic drifted.',
    );
}

try {
    NativeLibrary::target('Linux', 'x86_64', 'glibc 2.30');
    throw new RuntimeException('Old glibc target detection did not fail.');
} catch (RuntimeException $exception) {
    $assert(
        str_contains($exception->getMessage(), 'detected glibc 2.30'),
        'Old glibc rejection diagnostic drifted.',
    );
}

$expectedTarget = getenv('DROVER_EXPECT_TARGET') ?: null;
$expectedLibrary = getenv('DROVER_EXPECT_LIBRARY') ?: null;

if ($expectedTarget !== null) {
    $assert(NativeLibrary::target() === $expectedTarget, 'Runtime target detection drifted.');
}

if ($expectedLibrary !== null) {
    $assert(
        realpath(NativeLibrary::resolve()) === realpath($expectedLibrary),
        'Package-local native resolution drifted.',
    );
}

fwrite(STDOUT, json_encode([
    'gate' => 'native-library-resolution',
    'status' => 'passed',
], JSON_THROW_ON_ERROR).PHP_EOL);
