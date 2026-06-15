<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Th3Mouk\MaterializedView\Core\Database\DatabaseException;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewDefinition;
use Th3Mouk\MaterializedView\Core\Definition\RebuildStrategy;
use Th3Mouk\MaterializedView\Core\Definition\SqlFileSource;
use Th3Mouk\MaterializedView\Core\Dependency\DropDependentPolicy;
use Th3Mouk\MaterializedView\Core\Sql\ManagementMarker;
use Th3Mouk\MaterializedView\Core\Sync\MissingDependencyPolicy;
use Th3Mouk\MaterializedViewBundle\Command\Port\InitialRefreshDispatcher;
use Th3Mouk\MaterializedViewBundle\Command\SyncCommand;
use Th3Mouk\MaterializedViewBundle\Exception\AsyncRefreshRequiresTargetResolver;
use Th3Mouk\MaterializedViewBundle\Exception\UnknownRebuildStrategy;

#[Group('matview-commands')]
final class SyncCommandTest extends CommandTestCase
{
    public function testDryRunListsPlannedActionsWithoutExecuting(): void
    {
        $dispatcher = $this->createStub(InitialRefreshDispatcher::class);
        $tester = new CommandTester($this->command([$this->definition()], $dispatcher));

        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Create', $display);
        self::assertStringContainsString('public.demo', $display);
        self::assertStringContainsString('Dry run', $display);
    }

    public function testSyncReportsOutcome(): void
    {
        $dispatcher = $this->createStub(InitialRefreshDispatcher::class);
        $tester = new CommandTester($this->command([$this->definition()], $dispatcher));

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Sync complete', $tester->getDisplay());
    }

    public function testEnqueueRefreshDispatchesForCreatedViews(): void
    {
        $dispatcher = $this->createMock(InitialRefreshDispatcher::class);
        $dispatcher->method('canDispatch')->willReturn(true);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(MaterializedViewDefinition::class), false);

        $tester = new CommandTester($this->command([$this->definition()], $dispatcher));
        $exit = $tester->execute(['--enqueue-refresh' => true]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testEnqueueRefreshIsRefusedWhenAsyncIsNotRoutable(): void
    {
        $dispatcher = $this->createMock(InitialRefreshDispatcher::class);
        $dispatcher->method('canDispatch')->willReturn(false);
        $dispatcher->expects(self::never())->method('dispatch');

        $tester = new CommandTester($this->command([$this->definition()], $dispatcher));

        $this->expectException(AsyncRefreshRequiresTargetResolver::class);

        $tester->execute(['--enqueue-refresh' => true]);
    }

    public function testRejectsAnUnknownForcedStrategy(): void
    {
        $dispatcher = $this->createStub(InitialRefreshDispatcher::class);
        $tester = new CommandTester($this->command([$this->definition()], $dispatcher));

        $this->expectException(UnknownRebuildStrategy::class);

        $tester->execute(['--strategy' => 'in_place']);
    }

    public function testWithoutEnqueueRefreshDispatcherIsNotCalled(): void
    {
        $dispatcher = $this->createMock(InitialRefreshDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $tester = new CommandTester($this->command([$this->definition()], $dispatcher));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testAcceptsPruneAndStrategyOptions(): void
    {
        $dispatcher = $this->createStub(InitialRefreshDispatcher::class);
        $tester = new CommandTester($this->command([$this->definition()], $dispatcher));

        $exit = $tester->execute(['--prune' => true, '--strategy' => 'side_by_side', '--no-analyze' => true]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testForcedSideBySideStrategyDrivesTheSideBySideRebuilder(): void
    {
        $statements = [];
        $tester = new CommandTester($this->commandWith(
            $this->recordingConnection($statements, [$this->managedViewRow('public', 'demo', 'stale-hash')]),
            [$this->definition()],
        ));

        $exit = $tester->execute(['--strategy' => 'side_by_side']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue(
            $this->hasStatement($statements, 'CREATE MATERIALIZED VIEW', 'demo__mv_tmp_'),
            'Forcing --strategy=side_by_side must route the rebuild through the side-by-side rebuilder.',
        );
        self::assertFalse(
            $this->hasStatement($statements, 'CREATE MATERIALIZED VIEW "public"."demo" AS'),
            'A forced side-by-side rebuild must never drop and recreate the live view in place.',
        );
    }

    public function testForcedDropCreateStrategyOverridesAPerDefinitionSideBySideStrategy(): void
    {
        $statements = [];
        $tester = new CommandTester($this->commandWith(
            $this->recordingConnection($statements, [$this->managedViewRow('public', 'demo', 'stale-hash')]),
            [$this->definition()->withRebuildStrategy(RebuildStrategy::SideBySide)],
        ));

        $exit = $tester->execute(['--strategy' => 'drop_create']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue(
            $this->hasStatement($statements, 'DROP MATERIALIZED VIEW IF EXISTS "public"."demo"'),
            'Forcing --strategy=drop_create must override the definition-declared side-by-side strategy.',
        );
        self::assertTrue(
            $this->hasStatement($statements, 'CREATE MATERIALIZED VIEW "public"."demo" AS'),
        );
        self::assertFalse(
            $this->hasStatement($statements, 'demo__mv_tmp_'),
            'A forced drop-create rebuild must never build a side-by-side temporary view.',
        );
    }

    public function testAnalyzeAfterSyncDefaultDrivesTheAnalyzeStatement(): void
    {
        $withAnalyze = [];
        new CommandTester($this->commandWith(
            $this->recordingConnection($withAnalyze),
            [$this->populatedDefinition()],
            analyzeAfterSync: true,
        ))->execute([]);

        $withoutAnalyze = [];
        new CommandTester($this->commandWith(
            $this->recordingConnection($withoutAnalyze),
            [$this->populatedDefinition()],
            analyzeAfterSync: false,
        ))->execute([]);

        self::assertTrue($this->hasStatement($withAnalyze, 'ANALYZE', '"public"."demo"'));
        self::assertFalse($this->hasStatement($withoutAnalyze, 'ANALYZE', '"public"."demo"'));
    }

    public function testPruneOrphansByDefaultDrivesTheOrphanDrop(): void
    {
        $orphanRow = $this->managedViewRow('public', 'ghost', 'stale-orphan-hash');

        $pruned = [];
        new CommandTester($this->commandWith(
            $this->recordingConnection($pruned, [$orphanRow]),
            [$this->definition()],
            pruneOrphans: true,
        ))->execute([]);

        $kept = [];
        new CommandTester($this->commandWith(
            $this->recordingConnection($kept, [$orphanRow]),
            [$this->definition()],
            pruneOrphans: false,
        ))->execute([]);

        self::assertTrue($this->hasStatement($pruned, 'DROP MATERIALIZED VIEW', 'ghost'));
        self::assertFalse($this->hasStatement($kept, 'DROP MATERIALIZED VIEW', 'ghost'));
    }

    public function testPreserveExistingGrantsDefaultReplaysGrantsOnRebuild(): void
    {
        $driftedView = $this->managedViewRow('public', 'demo', 'stale-drift-hash');
        $grants = [['grantee' => 'reporting', 'privilege_type' => 'SELECT', 'is_grantable' => 'NO']];

        $preserved = [];
        new CommandTester($this->commandWith(
            $this->recordingConnection($preserved, [$driftedView], $grants, $driftedView),
            [$this->definition()],
            preserveExistingGrants: true,
        ))->execute([]);

        $discarded = [];
        new CommandTester($this->commandWith(
            $this->recordingConnection($discarded, [$driftedView], $grants, $driftedView),
            [$this->definition()],
            preserveExistingGrants: false,
        ))->execute([]);

        self::assertTrue($this->hasStatement($preserved, 'GRANT SELECT', 'reporting'));
        self::assertFalse($this->hasStatement($discarded, 'GRANT', 'reporting'));
    }

    public function testSkipPolicySurfacesSkippedViewsInTheOutcomeTable(): void
    {
        $statements = [];
        $tester = new CommandTester($this->commandWith(
            $this->recordingConnection($statements, failCreateForView: '"public"."demo"'),
            [$this->definition()],
            missingDependencyPolicy: MissingDependencyPolicy::Skip,
        ));

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Skipped', $display);
        self::assertStringContainsString('public.demo', $display);
        self::assertStringContainsString('missing schema/table', $display);
    }

    public function testFailPolicyLetsAMissingDependencyAbortTheSync(): void
    {
        $statements = [];
        $tester = new CommandTester($this->commandWith(
            $this->recordingConnection($statements, failCreateForView: '"public"."demo"'),
            [$this->definition()],
            missingDependencyPolicy: MissingDependencyPolicy::Fail,
        ));

        // Since lib 1.3, the DBAL connection is wrapped by the Core\Database\Connection port,
        // which surfaces driver errors as DatabaseException rather than the raw DBAL exception.
        $this->expectException(DatabaseException::class);

        $tester->execute([]);
    }

    public function testCascadePolicyRebuildsThroughDropCascade(): void
    {
        $statements = [];
        $tester = new CommandTester($this->commandWith(
            $this->recordingConnection($statements, [$this->managedViewRow('public', 'demo', 'stale-hash')], existingView: $this->managedViewRow('public', 'demo', 'stale-hash')),
            [$this->definition()],
            dropDependentPolicy: DropDependentPolicy::Cascade,
        ));

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertTrue($this->hasStatement($statements, 'DROP MATERIALIZED VIEW IF EXISTS "public"."demo" CASCADE'));
    }

    /**
     * @param iterable<MaterializedViewDefinition> $definitions
     */
    private function commandWith(
        Connection $connection,
        iterable $definitions,
        bool $analyzeAfterSync = true,
        bool $preserveExistingGrants = true,
        bool $pruneOrphans = false,
        MissingDependencyPolicy $missingDependencyPolicy = MissingDependencyPolicy::Fail,
        DropDependentPolicy $dropDependentPolicy = DropDependentPolicy::Refuse,
    ): SyncCommand {
        return new SyncCommand(
            $this->registry($definitions),
            $this->manager($connection),
            $this->comparator($connection),
            $this->createStub(InitialRefreshDispatcher::class),
            $analyzeAfterSync,
            $preserveExistingGrants,
            $pruneOrphans,
            true,
            $missingDependencyPolicy,
            $dropDependentPolicy,
        );
    }

    private function populatedDefinition(): MaterializedViewDefinition
    {
        return $this->definition()->withData();
    }

    /**
     * @param list<string>               $statements
     * @param list<array<string, mixed>> $schemaRows
     * @param list<array<string, mixed>> $grantRows
     * @param array<string, mixed>|null  $existingView
     */
    private function recordingConnection(
        array &$statements,
        array $schemaRows = [],
        array $grantRows = [],
        ?array $existingView = null,
        ?string $failCreateForView = null,
        string $failCreateSqlState = '42P01',
    ): Connection {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '"'.$identifier.'"',
        );
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAssociative')->willReturn($existingView ?? false);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($schemaRows, $grantRows): array {
                if (str_contains($sql, 'role_table_grants')) {
                    return $grantRows;
                }

                if (str_contains($sql, 'ORDER BY c.relname')) {
                    return $schemaRows;
                }

                return [];
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements, $failCreateForView, $failCreateSqlState): int {
                $statements[] = $sql;

                if (null !== $failCreateForView
                    && str_contains($sql, 'CREATE MATERIALIZED VIEW')
                    && str_contains($sql, $failCreateForView)
                ) {
                    $driver = new class('relation does not exist', $failCreateSqlState) extends DriverAbstractException {};

                    throw new DriverException($driver, null);
                }

                return 0;
            },
        );

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function managedViewRow(string $schema, string $name, string $hash): array
    {
        return [
            'schema_name' => $schema,
            'view_name' => $name,
            'definition' => 'SELECT 1',
            'is_populated' => true,
            'comment' => ManagementMarker::create($hash)->toJson(),
        ];
    }

    /**
     * @param list<string> $statements
     */
    private function hasStatement(array $statements, string ...$needles): bool
    {
        return array_any(
            $statements,
            static fn (string $statement): bool => array_all(
                $needles,
                static fn (string $needle): bool => str_contains($statement, $needle),
            ),
        );
    }

    /**
     * @param iterable<MaterializedViewDefinition> $definitions
     */
    private function command(iterable $definitions, InitialRefreshDispatcher $dispatcher): SyncCommand
    {
        $connection = $this->emptyConnection();

        return new SyncCommand(
            $this->registry($definitions),
            $this->manager($connection),
            $this->comparator($connection),
            $dispatcher,
            true,
            true,
            false,
            true,
            MissingDependencyPolicy::Fail,
            DropDependentPolicy::Refuse,
        );
    }

    private function definition(): MaterializedViewDefinition
    {
        return MaterializedViewDefinition::create('public.demo')
            ->fromSql(SqlFileSource::fromAbsolutePath(__DIR__.'/fixtures/sample.sql'));
    }
}
