<?php

declare(strict_types=1);

namespace Drove\Environment;

/** @experimental */
enum ResourceCapability: string
{
    case Branchable = 'branchable';
    case LeafIsolated = 'leaf-isolated';
    case ScopeIsolated = 'scope-isolated';
    case RollbackIsolated = 'rollback-isolated';
    case Resettable = 'resettable';
    case SharedReadOnly = 'shared-read-only';
}
