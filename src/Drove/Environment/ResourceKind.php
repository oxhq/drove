<?php

declare(strict_types=1);

namespace Drove\Environment;

/** @experimental */
enum ResourceKind: string
{
    case Database = 'database';
    case Filesystem = 'filesystem';
    case Cache = 'cache';
    case Queue = 'queue';
    case ObjectStorage = 'object-storage';
}
