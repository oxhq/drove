<?php

declare(strict_types=1);

namespace Drove\Contracts\Plugins;

interface Bootable
{
    /**
     * @param  list<string>  $arguments
     */
    public function bootDrove(array $arguments, string $rootPath): void;
}
