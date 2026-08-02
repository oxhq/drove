<?php

declare(strict_types=1);

require __DIR__.'/native-benchmark-runner.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $arguments = $_SERVER['argv'] ?? [];
        nativeBenchmarkRequire(
            count($arguments) >= 7 && count($arguments) <= 9,
            'Usage: php native-benchmark-prepare.php N1_N5_JSON CONFIG_JSON PEST_CHECKOUT INVOICESHELF_CHECKOUT LIVEWIRE_CHECKOUT FILAMENT_CHECKOUT [CPU_CORES] [MEMORY_BYTES]',
        );
        $root = dirname(__DIR__, 2);
        $evidence = nativeBenchmarkAbsolutePath($arguments[1], getcwd() ?: '.');
        nativeBenchmarkValidatePrerequisite(nativeBenchmarkReadJson($evidence));
        $output = nativeBenchmarkOutputPath($arguments[2], getcwd() ?: '.');
        nativeBenchmarkRequire($output !== $evidence, 'Native benchmark config cannot overwrite its N1-N5 evidence.');
        $checkouts = array_combine(DROVE_NATIVE_BENCHMARK_CORPORA, array_slice($arguments, 3, 4));
        $cpu = isset($arguments[7]) ? (float) $arguments[7] : 30.0;
        $memory = isset($arguments[8]) ? nativeBenchmarkPositiveInt($arguments[8], 'memory bytes') : 17_179_869_184;
        $quotas = nativeBenchmarkQuotas(['cpu_cores' => $cpu, 'memory_bytes' => $memory]);
        nativeBenchmarkPrepareConfig($root, $evidence, $output, $checkouts, $quotas);
        $plan = nativeBenchmarkBuildPlan($output, 7_331);
        fwrite(STDOUT, sprintf(
            "Native N6 config written to %s with %d randomized jobs.\n",
            $output,
            count($plan['schedule']),
        ));
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable->getMessage().PHP_EOL);
        exit(2);
    }
}

/**
 * @param  array<string, string>  $checkouts
 * @param  array{cpu_cores: float, memory_bytes: int}  $quotas
 */
function nativeBenchmarkPrepareConfig(
    string $root,
    string $evidence,
    string $output,
    array $checkouts,
    array $quotas,
): void {
    $evidencePayload = nativeBenchmarkReadJson($evidence);
    nativeBenchmarkValidatePrerequisite($evidencePayload);
    $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drove-native-n6-'.bin2hex(random_bytes(8));
    nativeBenchmarkRequire(mkdir($temporary, 0700, true), 'Cannot create the native benchmark build workspace.');

    try {
        $revision = trim(nativeBenchmarkRunnerProcess(['git', '-C', $root, 'rev-parse', 'HEAD'])['stdout']);
        nativeBenchmarkRequire(
            ($evidencePayload['drove_revision'] ?? null) === $revision,
            'The N1-N5 evidence was produced by a different Drove revision.',
        );
        nativeBenchmarkRequire(
            trim(nativeBenchmarkRunnerProcess(['git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all'])['stdout']) === '',
            'The Drove checkout must be clean before native benchmark image preparation.',
        );
        $definitions = nativeBenchmarkCorpusDefinitions($root);
        $definitionsById = array_column($definitions, null, 'id');
        $expectedRevisions = array_column($definitions, 'source_revision', 'id');
        $contexts = [];

        foreach (DROVE_NATIVE_BENCHMARK_CORPORA as $corpus) {
            $checkout = $checkouts[$corpus] ?? null;
            nativeBenchmarkRequire(is_string($checkout) && is_dir($checkout), "$corpus checkout is unavailable.");
            $checkout = nativeBenchmarkAbsolutePath($checkout, getcwd() ?: '.');
            nativeBenchmarkRequire(
                trim(nativeBenchmarkRunnerProcess(['git', '-C', $checkout, 'rev-parse', 'HEAD'])['stdout']) === $expectedRevisions[$corpus],
                "$corpus checkout revision drifted before image preparation.",
            );
            nativeBenchmarkRequire(
                trim(nativeBenchmarkRunnerProcess(['git', '-C', $checkout, 'status', '--porcelain=v1', '--untracked-files=all'])['stdout']) === '',
                "$corpus checkout must be clean before image preparation.",
            );
            $context = $temporary.DIRECTORY_SEPARATOR.$corpus;
            $clone = $context.DIRECTORY_SEPARATOR.'corpus';
            nativeBenchmarkRequire(mkdir($context, 0700), "Cannot create the $corpus build context.");
            nativeBenchmarkRunnerProcess(['git', 'clone', '--quiet', '--no-hardlinks', '--no-checkout', $checkout, $clone]);
            nativeBenchmarkRunnerProcess(['git', '-C', $clone, 'config', 'core.autocrlf', 'false']);
            nativeBenchmarkRunnerProcess(['git', '-C', $clone, 'checkout', '--quiet', '--detach', $expectedRevisions[$corpus]]);
            $contexts[$corpus] = $context;
        }

        nativeBenchmarkRunnerProcess([
            'docker', 'build', '--tag', 'drove-phase-three-laravel',
            '--file', $root.'/experiments/phase-3-laravel/Dockerfile', $root,
        ]);
        nativeBenchmarkRunnerProcess([
            'docker', 'build', '--tag', 'drove-corpus', '--build-arg', 'DROVE_REVISION='.$revision,
            '--file', $root.'/benchmarks/corpus/Dockerfile', $root,
        ]);
        $images = [];
        $imageLockHashes = [];

        foreach (DROVE_NATIVE_BENCHMARK_CORPORA as $corpus) {
            foreach (['baseline', 'native'] as $runner) {
                $tag = 'drove-native-n6-'.$corpus.'-'.$runner;
                $definition = $definitionsById[$corpus] ?? null;
                nativeBenchmarkRequire(is_array($definition), "$corpus benchmark definition is unavailable during image preparation.");
                $lock = $runner === 'baseline'
                    ? ($definition['baseline_lock'] ?? $contexts[$corpus].DIRECTORY_SEPARATOR.'corpus'.DIRECTORY_SEPARATOR.'composer.lock')
                    : ($definition['native_lock'] ?? null);
                nativeBenchmarkRequire(is_string($lock) && is_file($lock), "$corpus $runner lock is unavailable during image preparation.");
                $lockHash = nativeBenchmarkHash($lock);
                nativeBenchmarkRunnerProcess([
                    'docker', 'build', '--tag', $tag,
                    '--build-context', 'corpus-source='.$contexts[$corpus],
                    '--build-arg', 'DROVE_NATIVE_BENCHMARK_CORPUS='.$corpus,
                    '--build-arg', 'DROVE_NATIVE_BENCHMARK_RUNNER='.$runner,
                    '--build-arg', 'DROVE_NATIVE_BENCHMARK_LOCK_SHA256='.$lockHash,
                    '--file', $root.'/benchmarks/corpus/native-benchmark.Dockerfile', $root,
                ]);
                $image = trim(nativeBenchmarkRunnerProcess([
                    'docker', 'image', 'inspect', '--format={{.Id}}', $tag,
                ])['stdout']);
                nativeBenchmarkRequire(preg_match('/^sha256:[0-9a-f]{64}$/D', $image) === 1, "$corpus $runner image identity is invalid.");
                $images[$corpus][$runner] = $image;
                $imageLockHashes[$corpus][$runner] = $lockHash;
            }
        }

        $inputRoot = dirname($output).DIRECTORY_SEPARATOR.'native-benchmark-inputs';
        nativeBenchmarkRequire(is_dir($inputRoot) || mkdir($inputRoot, 0700, true), 'Cannot create the native benchmark input directory.');
        $lockCheckouts = [];

        foreach (['invoiceshelf', 'filament'] as $corpus) {
            $directory = $inputRoot.DIRECTORY_SEPARATOR.$corpus;
            nativeBenchmarkRequire(is_dir($directory) || mkdir($directory, 0700, true), "Cannot create the $corpus baseline lock directory.");
            $source = $contexts[$corpus].DIRECTORY_SEPARATOR.'corpus'.DIRECTORY_SEPARATOR.'composer.lock';
            $target = $directory.DIRECTORY_SEPARATOR.'composer.lock';
            nativeBenchmarkRequire(copy($source, $target), "Cannot pin the $corpus upstream lock.");
            $lockCheckouts[$corpus] = $directory;
        }
        $definitions = nativeBenchmarkCorpusDefinitions($root, $lockCheckouts);
        $corpora = [];

        foreach ($definitions as $definition) {
            $corpus = $definition['id'];
            nativeBenchmarkRequire(is_string($definition['baseline_lock']), "$corpus baseline lock is unavailable.");
            nativeBenchmarkRequire(
                nativeBenchmarkHash($definition['baseline_lock']) === $imageLockHashes[$corpus]['baseline']
                    && nativeBenchmarkHash($definition['native_lock']) === $imageLockHashes[$corpus]['native'],
                "$corpus dependency lock changed after its benchmark images were built.",
            );
            $corpora[] = [
                'id' => $corpus,
                'source_revision' => $definition['source_revision'],
                'baseline' => [
                    'lock' => $definition['baseline_lock'],
                    'command' => nativeBenchmarkDockerCommand($images[$corpus]['baseline'], $quotas, $corpus, 'baseline'),
                ],
                'native' => [
                    'lock' => $definition['native_lock'],
                    'command' => nativeBenchmarkDockerCommand($images[$corpus]['native'], $quotas, $corpus, 'native'),
                ],
                'cohorts' => $definition['cohorts'],
            ];
        }

        nativeBenchmarkRequire(
            trim(nativeBenchmarkRunnerProcess(['git', '-C', $root, 'rev-parse', 'HEAD'])['stdout']) === $revision
                && trim(nativeBenchmarkRunnerProcess(['git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all'])['stdout']) === '',
            'The Drove checkout changed during native benchmark image preparation.',
        );

        nativeBenchmarkWriteJson($output, [
            'schema_version' => 1,
            'repetitions' => 5,
            'n1_n5_evidence' => $evidence,
            'quotas' => $quotas,
            'corpora' => $corpora,
        ]);
    } finally {
        nativeBenchmarkRunnerRemoveTree($temporary);
    }
}
