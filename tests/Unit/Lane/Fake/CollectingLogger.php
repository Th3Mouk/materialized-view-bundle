<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Lane\Fake;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
