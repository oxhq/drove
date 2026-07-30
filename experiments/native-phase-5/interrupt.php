<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/support.php';

try {
    $replayDirectory = getenv('DROVE_PHASE5_REPLAY_DIR');
    $replayDirectory = is_string($replayDirectory) && $replayDirectory !== ''
        ? $replayDirectory
        : sys_get_temp_dir().'/drove-native-phase-5-replays-'.bin2hex(random_bytes(8));
    $workspace = sys_get_temp_dir().'/drove-native-phase-5-interrupt-'.bin2hex(random_bytes(8));
    nativePhaseFiveAssert(
        (is_dir($replayDirectory)
            || (mkdir($replayDirectory, 0700, true) && is_dir($replayDirectory)))
            && mkdir($workspace, 0700, true),
        'Could not create the native Phase 5 interruption workspace.',
    );
    $replayPath = $replayDirectory.'/native-phase-5-interruption.replay.json';
    nativePhaseFiveAssert(
        ! file_exists($replayPath),
        'Native Phase 5 will not overwrite its interruption replay.',
    );
    putenv('DROVE_PHASE5_INTERRUPT_WORKSPACE='.$workspace);
    putenv('DROVE_PHASE5_INTERRUPT_REPLAY='.$replayPath);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'ffi.enable=true', __DIR__.'/interrupt-case.php'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2),
    );
    nativePhaseFiveAssert(
        is_resource($process),
        'Could not start the native Phase 5 interruption case.',
    );
    fclose($pipes[0]);
    $status = proc_get_status($process);
    $deadline = hrtime(true) + 5_000_000_000;

    do {
        $ready = glob($workspace.'/*.ready');

        if (is_array($ready) && $ready !== []) {
            break;
        }

        usleep(1_000);
    } while (hrtime(true) < $deadline);

    nativePhaseFiveAssert(
        is_array($ready)
            && $ready !== []
            && $status['running']
            && $status['pid'] > 0
            && posix_kill($status['pid'], SIGINT),
        'Could not signal the active native Phase 5 interruption case.',
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $finalStatus = proc_get_status($process);
    $reportedExitCode = $finalStatus['exitcode'];
    $exitCode = proc_close($process);

    if ($exitCode === -1 && ! $finalStatus['running']) {
        $exitCode = $reportedExitCode;
    }

    nativePhaseFiveAssert(
        $exitCode === 0
            && is_string($stdout)
            && is_string($stderr)
            && $stderr === '',
        'Native Phase 5 interruption case failed: '.(is_string($stderr) ? trim($stderr) : ''),
    );
    $summary = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
    nativePhaseFiveAssert(
        is_array($summary)
            && ($summary['schema'] ?? null) === 1
            && ($summary['ok'] ?? null) === true
            && ($summary['fixture'] ?? null) === 'interruption'
            && ($summary['terminal_result_count'] ?? null) === 30
            && ($summary['orphan_pids'] ?? null) === []
            && ($summary['artifact_residue_count'] ?? null) === 0,
        'Native Phase 5 interruption summary is invalid.',
    );
    $entries = glob($workspace.'/*');
    nativePhaseFiveAssert(
        is_array($entries) && $entries === [],
        'Native Phase 5 interruption left state artifacts.',
    );
    nativePhaseFiveRemove($workspace);
    echo json_encode(
        $summary,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} catch (Throwable $throwable) {
    if (isset($workspace) && is_string($workspace) && str_contains($workspace, 'drove-native-phase-5')) {
        try {
            nativePhaseFiveRemove($workspace);
        } catch (Throwable) {
            //
        }
    }

    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL);
    exit(1);
}
