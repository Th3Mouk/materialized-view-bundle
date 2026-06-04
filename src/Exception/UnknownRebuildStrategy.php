<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Exception;

use InvalidArgumentException;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;

final class UnknownRebuildStrategy extends InvalidArgumentException implements MaterializedViewBundleError
{
    /**
     * @param list<RebuildStrategy> $allowed
     */
    public static function value(string $given, array $allowed): self
    {
        return new self(\sprintf(
            'Unknown rebuild strategy "%s". Use one of: %s.',
            $given,
            implode('|', array_map(static fn (RebuildStrategy $strategy): string => $strategy->value, $allowed)),
        ));
    }
}
