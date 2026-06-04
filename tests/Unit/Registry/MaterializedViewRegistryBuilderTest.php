<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedViewBundle\Exception\InvalidMaterializedViewProvider;
use Th3Mouk\MaterializedViewBundle\Registry\MaterializedViewRegistryBuilder;

#[Group('materialized-view-bundle')]
final class MaterializedViewRegistryBuilderTest extends TestCase
{
    public function testBuildsAnEmptyRegistryWhenNoProvidersAreTagged(): void
    {
        $builder = new MaterializedViewRegistryBuilder(new ServiceLocator([]), []);

        $registry = $builder->build();

        self::assertSame(0, $registry->count());
    }

    public function testCollectsDefinitionsFromMultipleProviders(): void
    {
        $first = new readonly class {
            /**
             * @return iterable<MaterializedViewDefinition>
             */
            public function definitions(): iterable
            {
                yield MaterializedViewDefinition::create('public.alpha');
                yield MaterializedViewDefinition::create('public.beta');
            }
        };

        $second = new readonly class {
            /**
             * @return iterable<MaterializedViewDefinition>
             */
            public function definitions(): iterable
            {
                yield MaterializedViewDefinition::create('public.gamma');
            }
        };

        $builder = new MaterializedViewRegistryBuilder(
            new ServiceLocator([
                'provider.first' => static fn (): object => $first,
                'provider.second' => static fn (): object => $second,
            ]),
            [
                'provider.first' => 'definitions',
                'provider.second' => 'definitions',
            ],
        );

        $registry = $builder->build();

        self::assertSame(3, $registry->count());
        self::assertTrue($registry->has('public.alpha'));
        self::assertTrue($registry->has('public.beta'));
        self::assertTrue($registry->has('public.gamma'));
    }

    public function testHonoursACustomProviderMethodName(): void
    {
        $provider = new readonly class {
            /**
             * @return iterable<MaterializedViewDefinition>
             */
            public function provide(): iterable
            {
                return [MaterializedViewDefinition::create('public.custom')];
            }
        };

        $builder = new MaterializedViewRegistryBuilder(
            new ServiceLocator(['provider.custom' => static fn (): object => $provider]),
            ['provider.custom' => 'provide'],
        );

        $registry = $builder->build();

        self::assertSame(1, $registry->count());
        self::assertTrue($registry->has('public.custom'));
    }

    public function testRejectsAProviderMissingTheConfiguredMethod(): void
    {
        $provider = new readonly class {
            /**
             * @return iterable<MaterializedViewDefinition>
             */
            public function definitions(): iterable
            {
                return [];
            }
        };

        $builder = new MaterializedViewRegistryBuilder(
            new ServiceLocator(['provider.broken' => static fn (): object => $provider]),
            ['provider.broken' => 'missing'],
        );

        $this->expectException(InvalidMaterializedViewProvider::class);
        $this->expectExceptionMessage('missing()');

        $builder->build();
    }

    public function testRejectsAProviderReturningANonIterable(): void
    {
        $provider = new readonly class {
            public function definitions(): mixed
            {
                return 'not-iterable';
            }
        };

        $builder = new MaterializedViewRegistryBuilder(
            new ServiceLocator(['provider.scalar' => static fn (): object => $provider]),
            ['provider.scalar' => 'definitions'],
        );

        $this->expectException(InvalidMaterializedViewProvider::class);
        $this->expectExceptionMessage('iterable');

        $builder->build();
    }

    public function testRejectsAProviderYieldingANonDefinition(): void
    {
        $provider = new readonly class {
            /**
             * @return iterable<object>
             */
            public function definitions(): iterable
            {
                yield new stdClass();
            }
        };

        $builder = new MaterializedViewRegistryBuilder(
            new ServiceLocator(['provider.wrong' => static fn (): object => $provider]),
            ['provider.wrong' => 'definitions'],
        );

        $this->expectException(InvalidMaterializedViewProvider::class);

        $builder->build();
    }
}
