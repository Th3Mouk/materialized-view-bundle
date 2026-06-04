<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Exception;

use LogicException;

final class InvalidMaterializedViewProvider extends LogicException implements MaterializedViewBundleError
{
    public static function missingMethod(string $serviceId, string $method): self
    {
        return new self(\sprintf(
            'The materialized view provider "%s" does not expose the configured method "%s()".',
            $serviceId,
            $method,
        ));
    }

    public static function notIterable(string $serviceId, string $method): self
    {
        return new self(\sprintf(
            'The materialized view provider "%s::%s()" must return an iterable of %s.',
            $serviceId,
            $method,
            'MaterializedViewDefinition',
        ));
    }

    public static function invalidDefinition(string $serviceId, string $method): self
    {
        return new self(\sprintf(
            'The materialized view provider "%s::%s()" yielded a value that is not a %s.',
            $serviceId,
            $method,
            'MaterializedViewDefinition',
        ));
    }
}
