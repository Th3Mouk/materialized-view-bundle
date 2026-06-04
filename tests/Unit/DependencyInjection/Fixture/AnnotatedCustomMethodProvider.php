<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\DependencyInjection\Fixture;

use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedViewBundle\Attribute\AsMaterializedViewProvider;

#[AsMaterializedViewProvider(method: 'provide')]
final class AnnotatedCustomMethodProvider
{
    /**
     * @return iterable<MaterializedViewDefinition>
     */
    public function provide(): iterable
    {
        yield MaterializedViewDefinition::create('public.custom_method_view');
    }
}
