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
        $lines = [sprintf('Drove %s', $run['run_id'] ?? '')];
        $counts = array_fill_keys(['passed', 'failed', 'blocked', 'skipped', 'todo'], 0);

        foreach ($tests as $test) {
            if (! is_array($test)) {
                continue;
            }

            $status = is_string($test['status'] ?? null) ? $test['status'] : 'failed';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $name = is_string($test['name'] ?? null)
                ? $test['name']
                : (string) ($test['id'] ?? 'unknown test');
            $lines[] = sprintf(' %s %s', $this->marker($status), $name);
            $message = $test['failure']['message'] ?? $test['message'] ?? null;

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

        $summary = [];

        foreach (['failed', 'blocked', 'skipped', 'todo', 'passed'] as $status) {
            if ($counts[$status] > 0) {
                $summary[] = sprintf('%d %s', $counts[$status], $status);
            }
        }

        $lines[] = '';
        $lines[] = sprintf('Tests: %s (%d)', implode(', ', $summary), count($tests));

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function marker(string $status): string
    {
        return match ($status) {
            'passed' => '✓',
            'skipped' => '-',
            'todo' => '…',
            'blocked' => '!',
            default => '⨯',
        };
    }
}
