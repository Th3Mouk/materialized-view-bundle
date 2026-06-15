<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Readiness;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedView\Core\Database\Connection;
use Th3Mouk\MaterializedView\Core\Definition\MaterializedViewName;
use Th3Mouk\MaterializedView\Core\Exception\ViewNotPopulated;
use Th3Mouk\MaterializedView\Core\Introspection\ReadinessChecker;
use Th3Mouk\MaterializedViewBundle\Readiness\MaterializedViewReadinessGuard;
use Th3Mouk\MaterializedViewBundle\Readiness\ReadinessCacheScope;

#[Group('materialized-view-bundle')]
final class MaterializedViewReadinessGuardTest extends TestCase
{
    public function testReportsAPopulatedViewAsReady(): void
    {
        $guard = $this->guardFor(populated: true);

        self::assertTrue($guard->isReady('public.sales_by_category'));

        $guard->ensureReadable('public.sales_by_category');
    }

    public function testRejectsAnUnpopulatedView(): void
    {
        $guard = $this->guardFor(populated: false);

        self::assertFalse($guard->isReady('public.sales_by_category'));

        $this->expectException(ViewNotPopulated::class);

        $guard->ensureReadable('public.sales_by_category');
    }

    public function testAcceptsAMaterializedViewNameValueObject(): void
    {
        $guard = $this->guardFor(populated: true);

        self::assertTrue($guard->isReady(MaterializedViewName::create('analytics', 'sales_by_category')));
    }

    public function testQualifiesABareNameWithTheDefaultSchema(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::isString(),
                self::callback(static fn (array $params): bool => 'public' === $params['schema_name']
                    && 'sales_by_category' === $params['view_name']),
            )
            ->willReturn(true);

        $guard = new MaterializedViewReadinessGuard(new ReadinessChecker($connection));

        self::assertTrue($guard->isReady('sales_by_category'));
    }

    public function testProcessScopeCachesAcrossReadsAndSurvivesReset(): void
    {
        $reads = 0;
        $guard = new MaterializedViewReadinessGuard(
            new ReadinessChecker($this->countingConnection($reads)),
            ReadinessCacheScope::Process,
        );

        $guard->isReady('public.sales_by_category');
        $guard->isReady('public.sales_by_category');
        $guard->reset();
        $guard->isReady('public.sales_by_category');

        self::assertSame(1, $reads);
    }

    public function testRequestScopeCachesAcrossReadsButClearsOnReset(): void
    {
        $reads = 0;
        $guard = new MaterializedViewReadinessGuard(
            new ReadinessChecker($this->countingConnection($reads)),
            ReadinessCacheScope::Request,
        );

        $guard->isReady('public.sales_by_category');
        $guard->isReady('public.sales_by_category');
        self::assertSame(1, $reads);

        $guard->reset();
        $guard->isReady('public.sales_by_category');

        self::assertSame(2, $reads);
    }

    public function testNoneScopeNeverCaches(): void
    {
        $reads = 0;
        $guard = new MaterializedViewReadinessGuard(
            new ReadinessChecker($this->countingConnection($reads)),
            ReadinessCacheScope::None,
        );

        $guard->isReady('public.sales_by_category');
        $guard->isReady('public.sales_by_category');
        $guard->isReady('public.sales_by_category');

        self::assertSame(3, $reads);
    }

    private function guardFor(bool $populated): MaterializedViewReadinessGuard
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($populated);

        return new MaterializedViewReadinessGuard(new ReadinessChecker($connection));
    }

    private function countingConnection(int &$reads): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static function () use (&$reads): bool {
                ++$reads;

                return true;
            },
        );

        return $connection;
    }
}
