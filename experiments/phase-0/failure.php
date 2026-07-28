<?php

declare(strict_types=1);

$requiredFunctions = [
    'pcntl_fork',
    'pcntl_waitpid',
    'posix_kill',
    'posix_setpgid',
];

foreach ($requiredFunctions as $function) {
    if (! function_exists($function)) {
        fwrite(STDERR, sprintf("Drove failure proof requires %s().\n", $function));
        exit(2);
    }
}

$rootPid = getmypid();

$fatalPid = pcntl_fork();

if ($fatalPid === -1) {
    throw new RuntimeException('Unable to fork the uncaught-error child.');
}

if ($fatalPid === 0) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');

    throw new RuntimeException('Injected uncaught child error.');
}

$fatalStatus = null;
$fatalWaited = 0;
$fatalDeadline = hrtime(true) + 1_000_000_000;

do {
    $fatalWaited = pcntl_waitpid($fatalPid, $fatalStatus, WNOHANG);

    if ($fatalWaited !== 0) {
        break;
    }

    usleep(1_000);
} while (hrtime(true) < $fatalDeadline);

if ($fatalWaited === 0) {
    posix_kill($fatalPid, SIGKILL);
    $fatalWaited = pcntl_waitpid($fatalPid, $fatalStatus);
}

$fatalResult = [
    'classification' => $fatalWaited === $fatalPid ? 'failed' : 'harness_error',
    'exit_code' => $fatalWaited === $fatalPid && pcntl_wifexited($fatalStatus)
        ? pcntl_wexitstatus($fatalStatus)
        : null,
    'signal' => $fatalWaited === $fatalPid && pcntl_wifsignaled($fatalStatus)
        ? pcntl_wtermsig($fatalStatus)
        : null,
];

$watch = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

if ($watch === false) {
    throw new RuntimeException('Unable to create the grandchild watch pipe.');
}

[$watchRead, $watchWrite] = $watch;
$timeoutPid = pcntl_fork();

if ($timeoutPid === -1) {
    fclose($watchRead);
    fclose($watchWrite);
    throw new RuntimeException('Unable to fork the timeout child.');
}

if ($timeoutPid === 0) {
    fclose($watchRead);

    if (! posix_setpgid(0, 0) || posix_getpgrp() !== getmypid()) {
        exit(70);
    }

    $leaderPid = getmypid();
    $grandchildPid = pcntl_fork();

    if ($grandchildPid === -1) {
        exit(71);
    }

    if ($grandchildPid === 0) {
        fwrite($watchWrite, json_encode([
            'pid' => getmypid(),
            'process_group' => posix_getpgrp(),
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        while (true) {
            sleep(1);
        }
    }

    fclose($watchWrite);

    while (true) {
        sleep(1);
    }
}

fclose($watchWrite);
stream_set_blocking($watchRead, false);

$timeoutStatus = null;
$timeoutWaited = 0;
$readyPayload = '';
$timeoutDeadline = hrtime(true) + 250_000_000;

do {
    if (strlen($readyPayload) < 512) {
        $readyPayload .= (string) fread($watchRead, 512 - strlen($readyPayload));
    }

    $timeoutWaited = pcntl_waitpid($timeoutPid, $timeoutStatus, WNOHANG);

    if ($timeoutWaited !== 0) {
        break;
    }

    usleep(1_000);
} while (hrtime(true) < $timeoutDeadline);

$readyLine = strstr($readyPayload, PHP_EOL, true);
$grandchild = $readyLine === false ? null : json_decode($readyLine, true);
$groupReady = is_array($grandchild)
    && $grandchild['process_group'] === $timeoutPid
    && $grandchild['pid'] !== $timeoutPid;
$groupKillSent = $timeoutWaited === 0
    && $groupReady
    && posix_kill(-$timeoutPid, SIGKILL);

if ($timeoutWaited === 0) {
    if (! $groupKillSent) {
        posix_kill($timeoutPid, SIGKILL);
    }

    $timeoutWaited = pcntl_waitpid($timeoutPid, $timeoutStatus);
}

$watchDeadline = hrtime(true) + 1_000_000_000;

while (! feof($watchRead) && hrtime(true) < $watchDeadline) {
    fread($watchRead, 512);
    usleep(1_000);
}

$grandchildPipeClosed = feof($watchRead);
fclose($watchRead);

$timeoutResult = [
    'classification' => $groupKillSent ? 'timed_out' : 'harness_error',
    'exit_code' => $timeoutWaited === $timeoutPid && pcntl_wifexited($timeoutStatus)
        ? pcntl_wexitstatus($timeoutStatus)
        : null,
    'signal' => $timeoutWaited === $timeoutPid && pcntl_wifsignaled($timeoutStatus)
        ? pcntl_wtermsig($timeoutStatus)
        : null,
    'grandchild_pid' => $grandchild['pid'] ?? null,
    'process_group' => $grandchild['process_group'] ?? null,
    'process_group_killed' => $groupKillSent,
    'grandchild_pipe_closed' => $grandchildPipeClosed,
];

$passed = getmypid() === $rootPid
    && $fatalResult === [
        'classification' => 'failed',
        'exit_code' => 255,
        'signal' => null,
    ]
    && $timeoutResult['classification'] === 'timed_out'
    && $timeoutResult['exit_code'] === null
    && $timeoutResult['signal'] === SIGKILL
    && $timeoutResult['grandchild_pid'] !== null
    && $timeoutResult['process_group'] === $timeoutPid
    && $timeoutResult['process_group_killed']
    && $timeoutResult['grandchild_pipe_closed'];

echo json_encode([
    'status' => $passed ? 'passed' : 'failed',
    'root_pid' => $rootPid,
    'root_survived' => getmypid() === $rootPid,
    'children' => [
        'uncaught_error' => $fatalResult,
        'timeout' => $timeoutResult,
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
