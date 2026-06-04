<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Lane;

use Th3Mouk\MaterializedView\Core\Sync\SyncOutcome;

final readonly class LaneResult
{
    private function __construct(
        public bool $migrationsPending,
        public bool $managedViewsDropped,
        public bool $migrated,
        public bool $synchronized,
        public bool $dryRun,
        public bool $lockContended,
        public ?SyncOutcome $outcome,
    ) {
    }

    public static function completed(
        bool $migrationsPending,
        bool $managedViewsDropped,
        SyncOutcome $outcome,
    ): self {
        return new self(
            migrationsPending: $migrationsPending,
            managedViewsDropped: $managedViewsDropped,
            migrated: true,
            synchronized: true,
            dryRun: false,
            lockContended: false,
            outcome: $outcome,
        );
    }

    public static function dryRun(bool $migrationsPending): self
    {
        return new self(
            migrationsPending: $migrationsPending,
            managedViewsDropped: false,
            migrated: true,
            synchronized: false,
            dryRun: true,
            lockContended: false,
            outcome: null,
        );
    }

    public static function dryRunLockContended(): self
    {
        return new self(
            migrationsPending: false,
            managedViewsDropped: false,
            migrated: false,
            synchronized: false,
            dryRun: true,
            lockContended: true,
            outcome: null,
        );
    }
}
