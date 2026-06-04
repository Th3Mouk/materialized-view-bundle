<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Registry;

use Psr\Container\ContainerInterface;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedViewBundle\Exception\InvalidMaterializedViewProvider;

final readonly class MaterializedViewRegistryBuilder
{
    /**
     * @param array<string, string> $providerMethods service id => method name
     */
    public function __construct(
        private ContainerInterface $providers,
        private array $providerMethods,
    ) {
    }

    public function build(): MaterializedViewRegistry
    {
        return MaterializedViewRegistry::fromDefinitions($this->collectDefinitions());
    }

    /**
     * @return iterable<MaterializedViewDefinition>
     */
    private function collectDefinitions(): iterable
    {
        foreach ($this->providerMethods as $serviceId => $method) {
            $provider = $this->providers->get($serviceId);

            if (!\is_object($provider) || !method_exists($provider, $method)) {
                throw InvalidMaterializedViewProvider::missingMethod($serviceId, $method);
            }

            $definitions = $provider->{$method}();

            if (!is_iterable($definitions)) {
                throw InvalidMaterializedViewProvider::notIterable($serviceId, $method);
            }

            foreach ($definitions as $definition) {
                if (!$definition instanceof MaterializedViewDefinition) {
                    throw InvalidMaterializedViewProvider::invalidDefinition($serviceId, $method);
                }

                yield $definition;
            }
        }
    }
}
