<?php

declare(strict_types=1);

namespace Drove\Extension;

enum ContributionKind: string
{
    case Matcher = 'matcher';
    case Context = 'context';
    case Planner = 'planner';
    case ResourceProvider = 'resource-provider';
    case Reporter = 'reporter';
    case Cli = 'cli';
}
