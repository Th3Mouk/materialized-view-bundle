<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Th3Mouk\MaterializedViewBundle\Attribute\AsMaterializedViewProvider;
use Th3Mouk\MaterializedViewBundle\Registry\MaterializedViewRegistryBuilder;

final class MaterializedViewProviderPass implements CompilerPassInterface
{
    public const string REGISTRY_BUILDER_SERVICE = 'th3mouk_materialized_view.registry_builder';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::REGISTRY_BUILDER_SERVICE)) {
            return;
        }

        $references = [];
        $methods = [];

        foreach ($container->findTaggedServiceIds(AsMaterializedViewProvider::TAG) as $serviceId => $tags) {
            $references[$serviceId] = new Reference($serviceId);
            $methods[$serviceId] = $this->methodFor($tags);
        }

        $locator = ServiceLocatorTagPass::register($container, $references, self::REGISTRY_BUILDER_SERVICE);

        $definition = $container->getDefinition(self::REGISTRY_BUILDER_SERVICE);
        $definition->setClass(MaterializedViewRegistryBuilder::class);
        $definition->setArgument('$providers', $locator);
        $definition->setArgument('$providerMethods', $methods);
    }

    /**
     * @param array<int|string, mixed> $tags
     */
    private function methodFor(array $tags): string
    {
        foreach ($tags as $attributes) {
            if (\is_array($attributes)
                && isset($attributes['method'])
                && \is_string($attributes['method'])
                && '' !== $attributes['method']
            ) {
                return $attributes['method'];
            }
        }

        return 'definitions';
    }
}
