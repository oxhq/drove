<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/benchmarks/corpus/PestDatasetIdentity.php';

function nativeFilamentCaseParityAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function nativeFilamentCaseOutput(DOMElement $case, string $element): string
{
    foreach ($case->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $element) {
            return str_replace("\r\n", "\n", $child->textContent);
        }
    }

    return '';
}

/**
 * @return list<array{id: string, status: string, assertions: int, stdout: string, stderr: string}>
 */
function nativeFilamentBaselineRowsFromJunit(string $path): array
{
    nativeFilamentCaseParityAssert(is_file($path), 'The independent Filament JUnit artifact is unavailable.');
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->load($path, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    nativeFilamentCaseParityAssert($loaded, 'The independent Filament JUnit artifact is invalid.');
    $rows = [];
    $datasetIndexes = [];

    foreach ($document->getElementsByTagName('testcase') as $case) {
        $file = str_replace('\\', '/', $case->getAttribute('file'));
        $separator = strpos($file, '::');

        if ($separator === false) {
            throw new RuntimeException('A Filament JUnit case has no source identity.');
        }

        $source = substr($file, 0, $separator);
        $identity = NativePestDatasetIdentity::split($case->getAttribute('name'));
        $base = $source.'::'.$identity['declaration'];
        $id = $base;

        if ($identity['suffix'] !== null) {
            $numericIndex = $datasetIndexes[$base] ?? 0;
            $key = NativePestDatasetIdentity::key($identity['suffix'], $numericIndex);
            nativeFilamentCaseParityAssert(is_string($key), 'The independent Filament dataset key is invalid.');
            $id .= '::dataset:'.$key;

            if (str_starts_with($key, 'index:')) {
                $datasetIndexes[$base] = $numericIndex + 1;
            }
        }

        nativeFilamentCaseParityAssert(! isset($rows[$id]), 'The independent Filament baseline emitted a duplicate case ID.');
        $failed = $case->getElementsByTagName('failure')->length > 0
            || $case->getElementsByTagName('error')->length > 0;
        $skipped = $case->getElementsByTagName('skipped')->length > 0;
        $assertions = filter_var(
            $case->getAttribute('assertions'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        if (! is_int($assertions)) {
            throw new RuntimeException('A Filament JUnit case has invalid assertions.');
        }

        $rows[$id] = [
            'id' => $id,
            'status' => $failed ? 'failed' : ($skipped ? 'skipped' : 'passed'),
            'assertions' => $assertions,
            'stdout' => nativeFilamentCaseOutput($case, 'system-out'),
            'stderr' => nativeFilamentCaseOutput($case, 'system-err'),
        ];
    }

    ksort($rows, SORT_STRING);

    return array_values($rows);
}

/**
 * @param  list<array<string, mixed>>  $tests
 * @return list<array{id: string, status: string, assertions: int, stdout: string, stderr: string}>
 */
function nativeFilamentRowsFromRun(array $tests): array
{
    $rows = [];
    foreach ($tests as $test) {
        $source = $test['source']['path'] ?? null;
        $nativeId = $test['id'] ?? null;
        $dataset = $test['dataset'] ?? null;
        nativeFilamentCaseParityAssert(
            is_string($source) && $source !== '' && is_string($nativeId),
            'A native Filament case has no source identity.',
        );
        $source = str_replace('\\', '/', $source);
        $prefix = 'test:file:'.$source.'::';
        nativeFilamentCaseParityAssert(
            str_starts_with($nativeId, $prefix),
            'A native Filament case ID does not match its source: '.json_encode([
                'id' => $nativeId,
                'source' => $source,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $identity = substr($nativeId, strlen($prefix));
        $datasetOffset = strpos($identity, '::dataset:');

        $nativeDatasetKey = $datasetOffset === false ? null : substr($identity, $datasetOffset + strlen('::dataset:'));
        $identity = $datasetOffset === false ? $identity : substr($identity, 0, $datasetOffset);

        $segments = explode('::', $identity);
        $encodedName = array_pop($segments);
        $display = [];

        foreach ($segments as $segment) {
            nativeFilamentCaseParityAssert(
                str_starts_with($segment, 'describe:'),
                'A native Filament case has an invalid describe identity.',
            );
            $display[] = '`'.rawurldecode(substr($segment, strlen('describe:'))).'`';
        }

        $display[] = rawurldecode($encodedName);
        $base = $source.'::'.implode(' → ', $display);
        $id = $base;

        if ($dataset !== null) {
            nativeFilamentCaseParityAssert(is_array($dataset), 'A native Filament dataset identity is invalid.');
            $key = $dataset['key'] ?? null;
            nativeFilamentCaseParityAssert(
                is_int($key) || is_string($key),
                'A native Filament dataset key is invalid.',
            );
            $canonicalKey = is_int($key) ? 'index:'.$key : 'name:'.rawurlencode($key);
            nativeFilamentCaseParityAssert(
                $nativeDatasetKey === $canonicalKey,
                'A native Filament dataset key does not match its case ID.',
            );
            $id .= '::dataset:'.$canonicalKey;
        } else {
            nativeFilamentCaseParityAssert($nativeDatasetKey === null, 'A native Filament case ID has an unexpected dataset key.');
        }

        $status = $test['status'] ?? null;
        $assertions = $test['assertions'] ?? null;
        $stdout = $test['stdout'] ?? null;
        $stderr = $test['stderr'] ?? null;
        nativeFilamentCaseParityAssert(
            is_string($status)
                && is_int($assertions)
                && $assertions >= 0
                && is_string($stdout)
                && is_string($stderr),
            'A native Filament case has an invalid terminal result.',
        );
        nativeFilamentCaseParityAssert(! isset($rows[$id]), 'The native Filament run emitted a duplicate case ID.');
        $rows[$id] = [
            'id' => $id,
            'status' => $status,
            'assertions' => $assertions,
            'stdout' => str_replace("\r\n", "\n", $stdout),
            'stderr' => str_replace("\r\n", "\n", $stderr),
        ];
    }

    ksort($rows, SORT_STRING);

    return array_values($rows);
}

/** @param list<array{id: string, status: string, assertions: int, stdout: string, stderr: string}> $rows */
function nativeFilamentCaseRowsHash(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
