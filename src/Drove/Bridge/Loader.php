<?php

declare(strict_types=1);

namespace Drove\Bridge;

use Drove\Compatibility\Registry;
use RuntimeException;
use UnexpectedValueException;

final readonly class Loader
{
    public function __construct(
        private Registry $registry,
    ) {
        //
    }

    /**
     * Loading is explicit and side-effect free. Availability checks may be
     * deferred so discovery can inspect an installed bridge without requiring
     * its optional framework dependency.
     *
     * @return class-string<BridgeEntrypoint>
     */
    public function load(string $id, bool $requireDependencies = true): string
    {
        $definition = $this->registry->bridge($id);
        $entrypoint = $definition['entrypoint'];

        if (! class_exists($entrypoint)) {
            throw new RuntimeException(sprintf(
                'Drove bridge %s entrypoint %s is not installed.',
                $id,
                $entrypoint,
            ));
        }

        if (! is_subclass_of($entrypoint, BridgeEntrypoint::class)) {
            throw new UnexpectedValueException(sprintf(
                'Drove bridge %s entrypoint does not implement the bridge contract.',
                $id,
            ));
        }

        if ($entrypoint::bridgeId() !== $id
            || $entrypoint::scopeIrSchema() !== ($this->registry->manifest()['scope_ir_schema'] ?? null)) {
            throw new UnexpectedValueException(sprintf(
                'Drove bridge %s entrypoint identity is incompatible with the registry.',
                $id,
            ));
        }

        if ($requireDependencies && ! $entrypoint::available()) {
            throw new RuntimeException(
                $entrypoint::unavailableDiagnostic()
                    ?? sprintf('Drove bridge %s dependencies are unavailable.', $id),
            );
        }

        /** @var class-string<BridgeEntrypoint> $entrypoint */
        return $entrypoint;
    }
}
