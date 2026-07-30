<?php

declare(strict_types=1);

namespace Drove\Extension\Contracts;

use Drove\Extension\MatchInput;
use Drove\Extension\MatchResult;

interface Matcher
{
    public function match(MatchInput $input): MatchResult;
}
