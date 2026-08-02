<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/benchmarks/corpus/PestDatasetIdentity.php';

final class NativeInvoiceShelfCaseSemantics
{
    /**
     * @param  list<string>  $files
     * @return list<array{id: string, status: string, assertions: int, stdout: string, stderr: string}>
     */
    public static function fromJunit(DOMDocument $document, array $files): array
    {
        $nodes = new DOMXPath($document)->query('//testcase');

        if ($nodes === false) {
            throw new RuntimeException('The InvoiceShelf JUnit document has no cases.');
        }

        $rows = [];
        $ordinals = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $file = str_replace('\\', '/', explode('::', $node->getAttribute('file'), 2)[0]);

            if (! in_array($file, $files, true)) {
                throw new RuntimeException('Unexpected InvoiceShelf JUnit source: '.$file.'.');
            }

            $identity = NativePestDatasetIdentity::split($node->getAttribute('name'));
            $id = 'test:file:'.$file.'::'.rawurlencode($identity['declaration']);

            if ($identity['suffix'] !== null) {
                $ordinal = $ordinals[$id] ?? 0;
                $key = NativePestDatasetIdentity::key($identity['suffix'], $ordinal);

                if (! is_string($key)) {
                    throw new RuntimeException('An InvoiceShelf JUnit dataset key is invalid.');
                }

                $id .= '::dataset:'.$key;

                if (str_starts_with($key, 'index:')) {
                    $ordinals['test:file:'.$file.'::'.rawurlencode($identity['declaration'])] = $ordinal + 1;
                }
            }

            $status = 'passed';

            foreach (['failure' => 'failed', 'error' => 'failed', 'skipped' => 'skipped'] as $element => $candidate) {
                if ($node->getElementsByTagName($element)->length > 0) {
                    $status = $candidate;
                    break;
                }
            }

            $rows[] = [
                'id' => $id,
                'status' => $status,
                'assertions' => (int) $node->getAttribute('assertions'),
                'stdout' => self::childText($node, 'system-out'),
                'stderr' => self::childText($node, 'system-err'),
            ];
        }

        return self::sortAndValidate($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $tests
     * @return list<array{id: string, status: string, assertions: int, stdout: string, stderr: string}>
     */
    public static function fromNative(array $tests): array
    {
        $rows = [];

        foreach ($tests as $test) {
            $id = $test['id'] ?? null;
            $source = $test['source']['path'] ?? null;
            $dataset = $test['dataset'] ?? null;
            $status = $test['status'] ?? null;
            $assertions = $test['assertions'] ?? null;
            $stdout = $test['stdout'] ?? null;
            $stderr = $test['stderr'] ?? null;

            if (! is_string($id)
                || ! is_string($source)
                || ! is_string($status)
                || ! is_int($assertions)
                || ! is_string($stdout)
                || ! is_string($stderr)) {
                throw new RuntimeException('A native InvoiceShelf result row is malformed.');
            }

            $prefix = 'test:file:'.str_replace('\\', '/', $source).'::';

            if (! str_starts_with($id, $prefix)) {
                throw new RuntimeException('A native InvoiceShelf case ID does not match its source.');
            }

            $datasetOffset = strrpos($id, '::dataset:');
            $nativeDatasetKey = $datasetOffset === false
                ? null
                : substr($id, $datasetOffset + strlen('::dataset:'));

            if ($dataset !== null) {
                $key = is_array($dataset) ? ($dataset['key'] ?? null) : null;

                if (! is_int($key) && ! is_string($key)) {
                    throw new RuntimeException('A native InvoiceShelf dataset key is invalid.');
                }

                $canonicalKey = is_int($key) ? 'index:'.$key : 'name:'.rawurlencode($key);

                if ($nativeDatasetKey !== $canonicalKey) {
                    throw new RuntimeException('A native InvoiceShelf dataset key does not match its case ID.');
                }
            } elseif ($nativeDatasetKey !== null) {
                throw new RuntimeException('A native InvoiceShelf case ID has an unexpected dataset key.');
            }

            $rows[] = [
                'id' => $id,
                'status' => $status,
                'assertions' => $assertions,
                'stdout' => self::normalizeOutput($stdout),
                'stderr' => self::normalizeOutput($stderr),
            ];
        }

        return self::sortAndValidate($rows);
    }

    private static function childText(DOMElement $case, string $name): string
    {
        $node = $case->getElementsByTagName($name)->item(0);

        return self::normalizeOutput($node instanceof DOMNode ? $node->textContent : '');
    }

    private static function normalizeOutput(string $output): string
    {
        return str_replace(["\r\n", "\r"], "\n", $output);
    }

    /**
     * @param  list<array{id: string, status: string, assertions: int, stdout: string, stderr: string}>  $rows
     * @return list<array{id: string, status: string, assertions: int, stdout: string, stderr: string}>
     */
    private static function sortAndValidate(array $rows): array
    {
        usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $ids = array_column($rows, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('The normalized InvoiceShelf case identities are not unique.');
        }

        return $rows;
    }
}
