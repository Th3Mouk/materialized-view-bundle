<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

interface LaneMigrator
{
    public function migrate(bool $dryRun): void;
}
