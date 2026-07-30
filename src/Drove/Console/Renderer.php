<?php

declare(strict_types=1);

namespace Drove\Console;

/**
 * @internal
 */
final class Renderer
{
    /**
     * @param  array<string, mixed>  $run
     */
    public function render(array $run): string
    {
        $tests = is_array($run['tests'] ?? null) ? $run['tests'] : [];
        $scopes = is_array($run['scopes'] ?? null) ? $run['scopes'] : [];
        $lines = [sprintf('Drove %s', $run['run_id'] ?? '')];
        $counts = array_fill_keys(['passed', 'failed', 'blocked', 'skipped', 'todo', 'incomplete', 'risky'], 0);
        $assertions = null;
        $assertionsComplete = true;

        foreach ($tests as $test) {
            if (! is_array($test)) {
                $assertionsComplete = false;

                continue;
            }

            $status = is_string($test['status'] ?? null) ? $test['status'] : 'failed';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $value = $test['value'] ?? null;
            $nativeAssertions = $test['assertions'] ?? null;

            if (is_int($nativeAssertions) && $nativeAssertions >= 0) {
                $assertions ??= 0;
                $assertions += $nativeAssertions;
            } elseif (is_array($value)
                && is_int($value['assertions'] ?? null)
                && is_string($value['phpunit_status'] ?? null)
                && is_string($value['test_case'] ?? null)) {
                $assertions ??= 0;
                $assertions += $value['assertions'];
            } else {
                $assertionsComplete = false;
            }

            if (! is_int($nativeAssertions)
                && in_array($status, ['failed', 'blocked'], true)) {
                $assertionsComplete = false;
            }

            $name = is_string($test['name'] ?? null)
                ? $test['name']
                : (string) ($test['id'] ?? 'unknown test');
            $lines[] = sprintf(' %s %s', $this->marker($status), $name);
            $message = $test['failure']['message'] ?? $test['message'] ?? null;

            if (! is_string($message)
                && in_array($status, ['skipped', 'todo', 'incomplete', 'risky'], true)
                && is_string(is_array($value) ? ($value['message'] ?? null) : $value)) {
                $message = is_array($value) ? $value['message'] : $value;
            }

            if (is_string($message) && $message !== '') {
                $lines[] = '   '.$message;
            }

            foreach (['stdout', 'stderr'] as $stream) {
                if (is_string($test[$stream] ?? null) && $test[$stream] !== '') {
                    foreach (preg_split('/\R/', rtrim($test[$stream])) ?: [] as $output) {
                        $lines[] = sprintf('   %s | %s', $stream, $output);
                    }
                }
            }

            foreach ($test['teardown_failures'] ?? [] as $failure) {
                if (is_array($failure) && is_string($failure['message'] ?? null)) {
                    $lines[] = '   teardown | '.$failure['message'];
                }
            }
        }

        foreach ($scopes as $scope) {
            if (! is_array($scope)) {
                continue;
            }

            $failures = is_array($scope['failures'] ?? null) ? $scope['failures'] : [];
            $hasOutput = array_any(
                ['stdout', 'stderr'],
                static fn (string $stream): bool => is_string($scope[$stream] ?? null)
                    && $scope[$stream] !== '',
            );

            if ($failures === [] && ! $hasOutput) {
                continue;
            }

            $id = is_string($scope['id'] ?? null) ? $scope['id'] : 'unknown scope';
            $lines[] = sprintf(' %s %s', $failures === [] ? '✓' : '⨯', $id);

            foreach ($failures as $failure) {
                if (! is_array($failure)) {
                    continue;
                }

                if (! is_string($failure['message'] ?? null)) {
                    continue;
                }

                $phase = is_string($failure['phase'] ?? null) ? $failure['phase'] : 'scope';
                $lines[] = sprintf('   %s | %s', $phase, $failure['message']);
            }

            foreach (['stdout', 'stderr'] as $stream) {
                if (is_string($scope[$stream] ?? null) && $scope[$stream] !== '') {
                    foreach (preg_split('/\R/', rtrim($scope[$stream])) ?: [] as $output) {
                        $lines[] = sprintf('   %s | %s', $stream, $output);
                    }
                }
            }
        }

        $extensionReportLines = $this->extensionReportLines($run['extension_reports'] ?? null);

        if ($extensionReportLines !== []) {
            $lines[] = '';
            $lines[] = 'Extension reports:';
            array_push($lines, ...$extensionReportLines);
        }

        $summary = [];

        foreach (['failed', 'blocked', 'risky', 'skipped', 'todo', 'incomplete', 'passed'] as $status) {
            if ($counts[$status] > 0) {
                $summary[] = sprintf('%d %s', $counts[$status], $status);
            }
        }

        $lines[] = '';

        if (($run['exit_code'] ?? 0) !== 0) {
            $lines[] = 'Run: failed';
        }

        if (($run['phpunit_warnings'] ?? false) === true) {
            $lines[] = 'PHPUnit warnings: detected';
        }

        $lines[] = sprintf('Tests: %s (%d)', implode(', ', $summary), count($tests));

        if ($assertionsComplete && is_int($assertions)) {
            $lines[] = sprintf('Assertions: %d', $assertions);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Reporter output is opaque presentation data. It is rendered in its own
     * labelled section and never participates in test status, assertion, or
     * exit-code aggregation.
     *
     * @return list<string>
     */
    private function extensionReportLines(mixed $reports): array
    {
        if (! is_array($reports) || ! array_is_list($reports)) {
            return [];
        }

        $lines = [];

        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }
            if (! is_string($report['owner'] ?? null)) {
                continue;
            }
            if (! is_string($report['key'] ?? null)) {
                continue;
            }
            if (! is_string($report['output'] ?? null)) {
                continue;
            }
            $lines[] = sprintf(' [%s:%s]', $report['owner'], $report['key']);

            if ($report['output'] === '') {
                continue;
            }

            $output = rtrim($report['output'], "\r\n");

            foreach (preg_split('/\R/', $output) ?: [$output] as $outputLine) {
                $lines[] = '   report | '.$outputLine;
            }
        }

        return $lines;
    }

    private function marker(string $status): string
    {
        return match ($status) {
            'passed' => '✓',
            'skipped' => '-',
            'todo' => '…',
            'incomplete' => '?',
            'risky' => 'R',
            'blocked' => '!',
            default => '⨯',
        };
    }
}
