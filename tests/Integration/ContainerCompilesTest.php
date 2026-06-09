<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Integration;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\HttpKernel\KernelInterface;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\MaterializedViewManager;
use Th3Mouk\MaterializedView\Core\Registry\MaterializedViewRegistry;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;
use Th3Mouk\MaterializedViewBundle\Command\DoctrineLaneCommand;
use Th3Mouk\MaterializedViewBundle\Command\DropCommand;
use Th3Mouk\MaterializedViewBundle\Command\SyncCommand;
use Th3Mouk\MaterializedViewBundle\Messenger\AsyncRefreshRequestHandler;

#[Group('materialized-view-bundle')]
final class ContainerCompilesTest extends KernelTestCase
{
    /**
     * @var array<string, mixed>
     */
    private static array $matviewConfig = [];

    protected function setUp(): void
    {
        self::$matviewConfig = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreHandlersRegisteredByTheKernel();
    }

    private function restoreHandlersRegisteredByTheKernel(): void
    {
        $exceptionHandler = set_exception_handler(null);
        restore_exception_handler();

        if (\is_array($exceptionHandler) && $exceptionHandler[0] instanceof ErrorHandler) {
            restore_exception_handler();
        }

        $errorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        if (\is_array($errorHandler) && $errorHandler[0] instanceof ErrorHandler) {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new MaterializedViewBundleTestKernel('test', false, self::$matviewConfig);
    }

    public function testContainerCompilesWithTheBundleRegistered(): void
    {
        $container = $this->bootContainer();

        self::assertTrue($container->has('th3mouk_materialized_view.manager'));
        self::assertInstanceOf(MaterializedViewManager::class, $container->get(MaterializedViewManager::class));
        self::assertInstanceOf(MaterializedViewRegistry::class, $container->get(MaterializedViewRegistry::class));
    }

    public function testLibraryLoggerIsRegisteredAndDefaultsToThePsrAbstractionWithoutMonolog(): void
    {
        $container = $this->bootContainer();

        self::assertTrue($container->has('th3mouk_materialized_view.logger'));
        self::assertInstanceOf(LoggerInterface::class, $container->get('th3mouk_materialized_view.logger'));
        self::assertTrue($container->getParameter('th3mouk_materialized_view.logging.enabled'));
        self::assertSame('materialized_view', $container->getParameter('th3mouk_materialized_view.logging.channel'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commandServiceProvider(): iterable
    {
        yield 'list' => ['th3mouk_materialized_view.command.list'];
        yield 'validate' => ['th3mouk_materialized_view.command.validate'];
        yield 'diff' => ['th3mouk_materialized_view.command.diff'];
        yield 'drop' => ['th3mouk_materialized_view.command.drop'];
        yield 'sync' => ['th3mouk_materialized_view.command.sync'];
        yield 'prune' => ['th3mouk_materialized_view.command.prune'];
        yield 'refresh' => ['th3mouk_materialized_view.command.refresh'];
        yield 'generate' => ['th3mouk_materialized_view.command.generate'];
        yield 'dump-sql' => ['th3mouk_materialized_view.command.dump_sql'];
        yield 'doctrine-lane' => ['th3mouk_materialized_view.command.doctrine_lane'];
    }

    #[DataProvider('commandServiceProvider')]
    public function testMatviewCommandIsRegisteredAndInstantiable(string $serviceId): void
    {
        $container = $this->bootContainer();

        self::assertTrue($container->has($serviceId));
        self::assertNotNull($container->get($serviceId));
    }

    public function testRegisteredCommandsAreDiscoverableByName(): void
    {
        $container = $this->bootContainer();

        self::assertInstanceOf(SyncCommand::class, $container->get('th3mouk_materialized_view.command.sync'));
        self::assertInstanceOf(DoctrineLaneCommand::class, $container->get('th3mouk_materialized_view.command.doctrine_lane'));
    }

    public function testAsyncRefreshHandlerAndLaneAreRegistered(): void
    {
        $container = $this->bootContainer();

        self::assertInstanceOf(
            AsyncRefreshRequestHandler::class,
            $container->get('th3mouk_materialized_view.messenger.async_refresh_handler'),
        );
        self::assertTrue($container->has('th3mouk_materialized_view.messenger.target_resolver'));
        self::assertTrue($container->has('th3mouk_materialized_view.shared_transport_guard'));
    }

    public function testOrmEventListenersAreRegistered(): void
    {
        $container = $this->bootContainer();

        self::assertTrue($container->has('th3mouk_materialized_view.orm.write_guard'));
        self::assertTrue($container->has('th3mouk_materialized_view.orm.post_load_listener'));
    }

    public function testDoctrineMigrationsMigrateCommandIsAvailableForTheLane(): void
    {
        $container = $this->bootContainer();

        self::assertTrue($container->has('doctrine_migrations.migrate_command'));
    }

    public function testOrmWriteGuardIsRemovedWhenDisabled(): void
    {
        self::$matviewConfig = ['doctrine' => ['orm_write_guard' => false]];

        $container = $this->bootContainer();

        self::assertFalse($container->has('th3mouk_materialized_view.orm.write_guard'));
        self::assertTrue($container->has('th3mouk_materialized_view.orm.post_load_listener'));
    }

    public function testMissingDependencyAndExternalDependentPoliciesDefaultToTheSafeValues(): void
    {
        $container = $this->bootContainer();

        self::assertSame('fail', $container->getParameter('th3mouk_materialized_view.sync.on_missing_dependency'));
        self::assertSame('refuse', $container->getParameter('th3mouk_materialized_view.drop.on_external_dependent'));

        self::assertSame(
            MissingDependencyPolicy::Fail,
            $container->get('th3mouk_materialized_view.sync.on_missing_dependency'),
        );
        self::assertSame(
            DropDependentPolicy::Refuse,
            $container->get('th3mouk_materialized_view.drop.on_external_dependent'),
        );
    }

    public function testOptInPoliciesFlowFromConfigIntoTheResolvedServices(): void
    {
        self::$matviewConfig = [
            'sync' => ['on_missing_dependency' => 'skip'],
            'drop' => ['on_external_dependent' => 'cascade'],
        ];

        $container = $this->bootContainer();

        self::assertSame(
            MissingDependencyPolicy::Skip,
            $container->get('th3mouk_materialized_view.sync.on_missing_dependency'),
        );
        self::assertSame(
            DropDependentPolicy::Cascade,
            $container->get('th3mouk_materialized_view.drop.on_external_dependent'),
        );
        self::assertInstanceOf(SyncCommand::class, $container->get('th3mouk_materialized_view.command.sync'));
        self::assertInstanceOf(DropCommand::class, $container->get('th3mouk_materialized_view.command.drop'));
    }

    public function testLaneDropStrategyDefaultsToAllOnPending(): void
    {
        $container = $this->bootContainer();

        self::assertSame('all_on_pending', $container->getParameter('th3mouk_materialized_view.lane.drop_strategy'));
        self::assertInstanceOf(DoctrineLaneCommand::class, $container->get('th3mouk_materialized_view.command.doctrine_lane'));
    }

    public function testReactiveLaneDropStrategyFlowsFromConfigIntoTheLaneCommand(): void
    {
        self::$matviewConfig = ['lane' => ['drop_strategy' => 'reactive_retry']];

        $container = $this->bootContainer();

        self::assertSame('reactive_retry', $container->getParameter('th3mouk_materialized_view.lane.drop_strategy'));
        self::assertInstanceOf(DoctrineLaneCommand::class, $container->get('th3mouk_materialized_view.command.doctrine_lane'));
    }

    private function bootContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
