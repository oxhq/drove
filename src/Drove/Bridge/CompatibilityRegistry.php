<?php

declare(strict_types=1);

namespace Drove\Bridge;

use JsonException;
use JsonSerializable;
use RuntimeException;
use UnexpectedValueException;

final readonly class CompatibilityRegistry implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    private function __construct(
        private array $manifest,
    ) {
        $this->validate();
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__, 3).'/resources/drove-bridge-compatibility.json';
        $contents = is_file($path) && is_readable($path)
            ? file_get_contents($path)
            : false;

        if (! is_string($contents)) {
            throw new RuntimeException('Drove could not read its bridge compatibility registry.');
        }

        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The Drove bridge compatibility registry is not valid JSON.',
                $exception->getCode(),
                previous: $exception,
            );
        }

        if (! is_array($manifest)) {
            throw new UnexpectedValueException(
                'The Drove bridge compatibility registry must be an object.',
            );
        }

        return new self($manifest);
    }

    /**
     * @return array{
     *     entrypoint: class-string<BridgeEntrypoint>,
     *     role: 'environment'|'frontend',
     *     status: 'bridge-only'
     * }
     */
    public function bridge(string $id): array
    {
        $bridge = $this->manifest['bridges'][$id] ?? null;

        if (! is_array($bridge)) {
            throw new UnexpectedValueException(sprintf(
                'Drove compatibility bridge %s is not registered.',
                $id,
            ));
        }

        /** @var array{entrypoint: class-string<BridgeEntrypoint>, role: 'environment'|'frontend', status: 'bridge-only'} $bridge */
        return $bridge;
    }

    /**
     * @return array{
     *     bridge: string|null,
     *     codemod: string|null,
     *     diagnostic: string,
     *     status: 'bridge-only'|'supported'|'unsupported'
     * }
     */
    public function surface(string $id): array
    {
        $surface = $this->manifest['surfaces'][$id] ?? null;

        if (! is_array($surface)) {
            throw new UnexpectedValueException(sprintf(
                'Drove compatibility surface %s is not registered.',
                $id,
            ));
        }

        /** @var array{bridge: string|null, codemod: string|null, diagnostic: string, status: 'bridge-only'|'supported'|'unsupported'} $surface */
        return $surface;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(
            $this->canonical($this->manifest),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->manifest;
    }

    private function validate(): void
    {
        $this->exactKeys(
            $this->manifest,
            ['bridges', 'registry_version', 'schema', 'scope_ir_schema', 'surfaces'],
            'registry',
        );

        if (($this->manifest['schema'] ?? null) !== 1
            || ! is_int($this->manifest['registry_version'] ?? null)
            || $this->manifest['registry_version'] < 1
            || ($this->manifest['scope_ir_schema'] ?? null) !== 1) {
            $this->invalid('identity');
        }

        $bridges = $this->manifest['bridges'] ?? null;
        $surfaces = $this->manifest['surfaces'] ?? null;

        if (! is_array($bridges) || $bridges === []
            || ! is_array($surfaces) || $surfaces === []) {
            $this->invalid('contents');
        }

        foreach ($bridges as $id => $bridge) {
            $this->identifier($id, 'bridge');

            if (! is_array($bridge)) {
                $this->invalid('bridge '.$id);
            }

            $this->exactKeys($bridge, ['entrypoint', 'role', 'status'], 'bridge '.$id);

            if (! is_string($bridge['entrypoint'] ?? null)
                || preg_match(
                    '/^(?:[A-Z_a-z\x80-\xff][A-Z_a-z0-9\x80-\xff]*\\\\)+[A-Z_a-z\x80-\xff][A-Z_a-z0-9\x80-\xff]*$/D',
                    $bridge['entrypoint'],
                ) !== 1
                || ! in_array($bridge['role'] ?? null, ['environment', 'frontend'], true)
                || ($bridge['status'] ?? null) !== CompatibilityStatus::BridgeOnly->value) {
                $this->invalid('bridge '.$id);
            }
        }

        foreach ($surfaces as $id => $surface) {
            $this->identifier($id, 'surface');

            if (! is_array($surface)) {
                $this->invalid('surface '.$id);
            }

            $this->exactKeys(
                $surface,
                ['bridge', 'codemod', 'diagnostic', 'status'],
                'surface '.$id,
            );
            $status = is_string($surface['status'] ?? null)
                ? CompatibilityStatus::tryFrom($surface['status'])
                : null;
            $bridge = $surface['bridge'] ?? null;
            $codemod = $surface['codemod'] ?? null;
            $diagnostic = $surface['diagnostic'] ?? null;

            if (! $status instanceof CompatibilityStatus
                || (! is_null($bridge) && ! is_string($bridge))
                || (! is_null($codemod) && (! is_string($codemod) || $codemod === ''))
                || ! is_string($diagnostic)
                || preg_match('/^DROVE_[A-Z0-9_]+$/D', $diagnostic) !== 1) {
                $this->invalid('surface '.$id);
            }

            if ($status === CompatibilityStatus::BridgeOnly) {
                if (! is_string($bridge) || ! array_key_exists($bridge, $bridges)) {
                    $this->invalid('surface '.$id.' bridge');
                }
            } elseif ($bridge !== null) {
                $this->invalid('surface '.$id.' bridge');
            }
        }
    }

    private function identifier(mixed $id, string $owner): void
    {
        if (! is_string($id)
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $id) !== 1) {
            $this->invalid($owner.' identifier');
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expected
     */
    private function exactKeys(array $value, array $expected, string $owner): void
    {
        $keys = array_keys($value);

        if (array_any($keys, static fn (mixed $key): bool => ! is_string($key))) {
            $this->invalid($owner);
        }

        sort($keys, SORT_STRING);

        if ($keys !== $expected) {
            $this->invalid($owner);
        }
    }

    private function invalid(string $owner): never
    {
        throw new UnexpectedValueException(sprintf(
            'The Drove bridge compatibility registry contains an invalid %s.',
            $owner,
        ));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as &$item) {
            $item = $this->canonical($item);
        }

        return $value;
    }
}
