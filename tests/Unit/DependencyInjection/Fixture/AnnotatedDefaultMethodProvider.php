<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\DependencyInjection\Fixture;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedViewBundle\Attribute\AsMaterializedViewProvider;

#[AsMaterializedViewProvider]
final class AnnotatedDefaultMethodProvider
{
    /**
     * @return iterable<MaterializedViewDefinition>
     */
    public function definitions(): iterable
    {
        yield MaterializedViewDefinition::create('public.default_method_view');
    }
}
