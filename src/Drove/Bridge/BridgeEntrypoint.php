<?php

declare(strict_types=1);

namespace Drove\Bridge;

/**
 * The deliberately small front door shared by optional compatibility bridges.
 *
 * Loading an entrypoint must not register hooks, execute a suite, or mutate
 * kernel state. Dependency checks remain explicit so bridge discovery stays
 * data-only.
 */
interface BridgeEntrypoint
{
    public static function bridgeId(): string;

    public static function scopeIrSchema(): int;

    public static function available(): bool;

    public static function unavailableDiagnostic(): ?string;
}
