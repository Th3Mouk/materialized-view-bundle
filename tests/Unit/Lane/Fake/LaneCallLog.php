<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake;

final class LaneCallLog
{
    /** @var list<string> */
    private array $calls = [];

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function contains(string $call): bool
    {
        return \in_array($call, $this->calls, true);
    }

    public function last(): ?string
    {
        return [] === $this->calls ? null : $this->calls[array_key_last($this->calls)];
    }

    public function indexOf(string $call): ?int
    {
        $index = array_search($call, $this->calls, true);

        return false === $index ? null : $index;
    }
}
