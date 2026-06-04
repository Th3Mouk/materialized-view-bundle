<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Readiness;

enum ReadinessCacheScope: string
{
    case Request = 'request';
    case Process = 'process';
    case None = 'none';

    public function cachesAcrossReads(): bool
    {
        return self::None !== $this;
    }

    public function clearsOnContainerReset(): bool
    {
        return self::Request === $this;
    }
}
