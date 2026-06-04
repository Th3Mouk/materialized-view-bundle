<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Readiness;

use Symfony\Contracts\Service\ResetInterface;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;

final readonly class MaterializedViewReadinessGuard implements ResetInterface
{
    public function __construct(
        private ReadinessChecker $readinessChecker,
        private ReadinessCacheScope $cacheScope = ReadinessCacheScope::Request,
    ) {
    }

    public function isReady(string|MaterializedViewName $name): bool
    {
        $name = $this->normalize($name);

        $this->bypassCacheIfDisabled($name);

        return $this->readinessChecker->isReady($name);
    }

    /**
     * @throws ViewNotPopulated
     */
    public function ensureReadable(string|MaterializedViewName $name): void
    {
        $name = $this->normalize($name);

        $this->bypassCacheIfDisabled($name);

        $this->readinessChecker->ensureReadable($name);
    }

    public function forget(string|MaterializedViewName $name): void
    {
        $this->readinessChecker->forget($this->normalize($name));
    }

    public function reset(): void
    {
        if (!$this->cacheScope->clearsOnContainerReset()) {
            return;
        }

        $this->readinessChecker->forgetAll();
    }

    private function bypassCacheIfDisabled(MaterializedViewName $name): void
    {
        if ($this->cacheScope->cachesAcrossReads()) {
            return;
        }

        $this->readinessChecker->forget($name);
    }

    private function normalize(string|MaterializedViewName $name): MaterializedViewName
    {
        return $name instanceof MaterializedViewName
            ? $name
            : MaterializedViewName::fromString($name);
    }
}
