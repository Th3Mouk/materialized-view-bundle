<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsMaterializedViewProvider
{
    public const string TAG = 'th3mouk.materialized_view_provider';

    public function __construct(
        public string $method = 'definitions',
    ) {
    }
}
