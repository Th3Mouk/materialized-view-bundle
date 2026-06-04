<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedViewBundle\Attribute\AsMaterializedViewProvider;
use Th3Mouk\MaterializedViewBundle\DependencyInjection\Compiler\MaterializedViewProviderPass;
use Th3Mouk\MaterializedViewBundle\Registry\MaterializedViewRegistryBuilder;
use Th3Mouk\MaterializedViewBundle\Tests\Unit\DependencyInjection\Fixture\AnnotatedCustomMethodProvider;
use Th3Mouk\MaterializedViewBundle\Tests\Unit\DependencyInjection\Fixture\AnnotatedDefaultMethodProvider;
use Th3Mouk\MaterializedViewBundle\Th3MoukMaterializedViewBundle;

#[Group('materialized-view-bundle')]
final class MaterializedViewProviderAutoconfigurationTest extends TestCase
{
    public function testBundleBuildRegistersTheProviderCompilerPass(): void
    {
        $container = new ContainerBuilder();

        new Th3MoukMaterializedViewBundle()->build($container);

        $passes = array_map(
            static fn (object $pass): string => $pass::class,
            $container->getCompiler()->getPassConfig()->getBeforeOptimizationPasses(),
        );

        self::assertContains(MaterializedViewProviderPass::class, $passes);
    }

    public function testAttributeAutoconfigurationTagsProvidersWithTheirMethod(): void
    {
        $container = $this->containerWithProviderAutoconfiguration();

        $container->register(AnnotatedDefaultMethodProvider::class, AnnotatedDefaultMethodProvider::class)
            ->setAutoconfigured(true)
            ->setPublic(true);
        $container->register(AnnotatedCustomMethodProvider::class, AnnotatedCustomMethodProvider::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container->compile();

        $defaultTags = $container->getDefinition(AnnotatedDefaultMethodProvider::class)->getTag(AsMaterializedViewProvider::TAG);
        $customTags = $container->getDefinition(AnnotatedCustomMethodProvider::class)->getTag(AsMaterializedViewProvider::TAG);

        self::assertSame([['method' => 'definitions']], $defaultTags);
        self::assertSame([['method' => 'provide']], $customTags);
    }

    public function testCompilerPassWiresTaggedProvidersIntoTheRegistryBuilder(): void
    {
        $container = $this->containerWithProviderAutoconfiguration();
        $container->addCompilerPass(new MaterializedViewProviderPass());

        $container->register(MaterializedViewProviderPass::REGISTRY_BUILDER_SERVICE, MaterializedViewRegistryBuilder::class)
            ->setArgument('$providers', null)
            ->setArgument('$providerMethods', [])
            ->setPublic(true);

        $container->register(AnnotatedDefaultMethodProvider::class, AnnotatedDefaultMethodProvider::class)
            ->setAutoconfigured(true);
        $container->register(AnnotatedCustomMethodProvider::class, AnnotatedCustomMethodProvider::class)
            ->setAutoconfigured(true);

        $container->compile();

        $builder = $container->get(MaterializedViewProviderPass::REGISTRY_BUILDER_SERVICE);
        self::assertInstanceOf(MaterializedViewRegistryBuilder::class, $builder);

        $registry = $builder->build();
        self::assertInstanceOf(MaterializedViewRegistry::class, $registry);
        self::assertSame(2, $registry->count());
        self::assertTrue($registry->has('public.default_method_view'));
        self::assertTrue($registry->has('public.custom_method_view'));
    }

    public function testCompilerPassIsANoOpWhenTheRegistryBuilderServiceIsAbsent(): void
    {
        $container = $this->containerWithProviderAutoconfiguration();
        $container->addCompilerPass(new MaterializedViewProviderPass());

        $container->register(AnnotatedDefaultMethodProvider::class, AnnotatedDefaultMethodProvider::class)
            ->setAutoconfigured(true);

        $container->compile();

        self::assertFalse($container->hasDefinition(MaterializedViewProviderPass::REGISTRY_BUILDER_SERVICE));
    }

    private function containerWithProviderAutoconfiguration(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $container->registerAttributeForAutoconfiguration(
            AsMaterializedViewProvider::class,
            static function (ChildDefinition $definition, AsMaterializedViewProvider $attribute): void {
                $definition->addTag(AsMaterializedViewProvider::TAG, ['method' => $attribute->method]);
            },
        );

        return $container;
    }
}
