<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Command\Port;

final readonly class ScaffoldResult
{
    /**
     * @param list<string> $createdFiles
     * @param list<string> $updatedFiles
     */
    public function __construct(
        public array $createdFiles,
        public array $updatedFiles,
    ) {
    }

    /**
     * @param list<string> $createdFiles
     * @param list<string> $updatedFiles
     */
    public static function of(array $createdFiles, array $updatedFiles = []): self
    {
        return new self(array_values($createdFiles), array_values($updatedFiles));
    }
}
