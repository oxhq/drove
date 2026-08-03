<?php

declare(strict_types=1);

require_once __DIR__.'/native-benchmark.php';

/**
 * @param  array<string, string>  $checkouts
 * @return list<array{
 *     id: string,
 *     source_revision: string,
 *     baseline_lock: ?string,
 *     native_lock: string,
 *     cohorts: list<array{id: string, mode: string, c1_only_reason: ?string, expected: array{cases: int, assertions: int, statuses: array<string, int>, semantic_hash: string}}>
 * }>
 */
function nativeBenchmarkCorpusDefinitions(string $root, array $checkouts = []): array
{
    $root = nativeBenchmarkAbsolutePath($root, getcwd() ?: '.');
    $read = static fn (string $path): array => nativeBenchmarkReadJson($root.'/'.$path);
    $pest = $read('experiments/native-corpus-1/baseline.json');
    $invoice = $read('experiments/native-invoiceshelf-corpus/detailed-baseline.json');
    $livewireParallel = $read('experiments/phase-3-laravel/native-package/livewire-parallel-baseline.json');
    $livewireFull = $read('experiments/phase-3-laravel/native-package/livewire-full-baseline.json');
    $filament = $read('experiments/native-filament-corpus/baseline-cases.json');

    $outcome = static function (array $cases, ?int $assertions = null, ?string $hash = null): array {
        nativeBenchmarkRequire($cases !== [] && array_is_list($cases), 'A native benchmark case baseline is empty or invalid.');
        usort($cases, static fn (array $left, array $right): int => ($left['id'] ?? '') <=> ($right['id'] ?? ''));
        $statuses = array_count_values(array_column($cases, 'status'));
        ksort($statuses, SORT_STRING);

        return nativeBenchmarkExpectedOutcome([
            'cases' => count($cases),
            'assertions' => $assertions ?? array_sum(array_column($cases, 'assertions')),
            'statuses' => $statuses,
            'semantic_hash' => $hash ?? hash('sha256', json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ], 'native corpus baseline');
    };
    $checkoutLock = static function (string $corpus) use ($checkouts): ?string {
        $checkout = $checkouts[$corpus] ?? null;

        if (! is_string($checkout) || $checkout === '') {
            return null;
        }

        return nativeBenchmarkAbsolutePath($checkout, getcwd() ?: '.').'/composer.lock';
    };
    $invoiceCases = $invoice['cases'] ?? null;
    $parallelCases = $livewireParallel['cases'] ?? null;
    $fullCases = $livewireFull['cases'] ?? null;
    $filamentNonserial = $filament['cohorts']['nonserial'] ?? null;
    $filamentSerial = $filament['cohorts']['serial'] ?? null;
    nativeBenchmarkRequire(is_array($pest['cases'] ?? null), 'The Pest case baseline is invalid.');
    nativeBenchmarkRequire(is_array($invoiceCases), 'The InvoiceShelf case baseline is invalid.');
    nativeBenchmarkRequire(is_array($parallelCases) && is_array($fullCases), 'The Livewire case baselines are invalid.');
    nativeBenchmarkRequire(is_array($filamentNonserial) && is_array($filamentSerial), 'The Filament case baselines are invalid.');

    return [
        [
            'id' => 'pest',
            'source_revision' => '6b2cd358e8a9d6d1abb93804b70e1c659bbc411b',
            'baseline_lock' => $root.'/benchmarks/corpus/locks/pest-baseline.lock',
            'native_lock' => $root.'/benchmarks/corpus/locks/pest-native.lock',
            'cohorts' => [[
                'id' => 'selected',
                'mode' => 'parallel',
                'c1_only_reason' => null,
                'expected' => $outcome($pest['cases']),
            ]],
        ],
        [
            'id' => 'invoiceshelf',
            'source_revision' => '403a4d67225a153838ec126c484339abf60229d1',
            'baseline_lock' => $checkoutLock('invoiceshelf'),
            'native_lock' => $root.'/benchmarks/corpus/locks/invoiceshelf-native.lock',
            'cohorts' => [[
                'id' => 'selected',
                'mode' => 'parallel',
                'c1_only_reason' => null,
                'expected' => $outcome(
                    $invoiceCases,
                    is_int($invoice['assertions'] ?? null) ? $invoice['assertions'] : null,
                    is_string($invoice['case_semantics_sha256'] ?? null) ? $invoice['case_semantics_sha256'] : null,
                ),
            ]],
        ],
        [
            'id' => 'livewire',
            'source_revision' => '9c1450739d30c9b0b223ad6512be2a33f8f62f96',
            'baseline_lock' => $root.'/benchmarks/corpus/locks/livewire-baseline.lock',
            'native_lock' => $root.'/benchmarks/corpus/locks/livewire-native.lock',
            'cohorts' => [
                [
                    'id' => 'parallel',
                    'mode' => 'parallel',
                    'c1_only_reason' => null,
                    'expected' => $outcome($parallelCases),
                ],
                [
                    'id' => 'full',
                    'mode' => 'serial-resource',
                    'c1_only_reason' => 'The remaining pinned cases use unmanaged filesystem paths and stay in the explicit C1 full-suite cohort.',
                    'expected' => $outcome(
                        $fullCases,
                        is_int($livewireFull['expected_assertions'] ?? null) ? $livewireFull['expected_assertions'] : null,
                    ),
                ],
            ],
        ],
        [
            'id' => 'filament',
            'source_revision' => 'e9348b2e3792088ee877068116b6c1e1559a7df8',
            'baseline_lock' => $checkoutLock('filament'),
            'native_lock' => $root.'/benchmarks/corpus/locks/filament-native.lock',
            'cohorts' => [
                [
                    'id' => 'nonserial',
                    'mode' => 'parallel',
                    'c1_only_reason' => null,
                    'expected' => $outcome(
                        $filamentNonserial['rows'] ?? [],
                        is_int($filamentNonserial['assertions'] ?? null) ? $filamentNonserial['assertions'] : null,
                        is_string($filamentNonserial['semantic_sha256'] ?? null) ? $filamentNonserial['semantic_sha256'] : null,
                    ),
                ],
                [
                    'id' => 'serial',
                    'mode' => 'serial-resource',
                    'c1_only_reason' => 'The pinned Filament serial group mutates shared filesystem view paths.',
                    'expected' => $outcome(
                        $filamentSerial['rows'] ?? [],
                        is_int($filamentSerial['assertions'] ?? null) ? $filamentSerial['assertions'] : null,
                        is_string($filamentSerial['semantic_sha256'] ?? null) ? $filamentSerial['semantic_sha256'] : null,
                    ),
                ],
            ],
        ],
    ];
}
