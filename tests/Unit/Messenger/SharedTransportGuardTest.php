<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Unit\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Th3Mouk\MaterializedViewBundle\Messenger\AsyncRefreshRequiresSharedTransport;
use Th3Mouk\MaterializedViewBundle\Messenger\SharedTransportGuard;
use Th3Mouk\MaterializedViewBundle\Messenger\TransportScope;

#[Group('materialized-view-bundle')]
final class SharedTransportGuardTest extends TestCase
{
    public function testSharedTransportSatisfiesTheRequirement(): void
    {
        $guard = new SharedTransportGuard(
            requireSharedTransport: true,
            transportScope: TransportScope::Shared,
        );

        self::assertTrue($guard->isSatisfied());

        $guard->ensureAsyncIsSupported();
    }

    public function testRefusesPerConnectionDoctrineTransportWhenSharedTransportIsRequired(): void
    {
        $guard = new SharedTransportGuard(
            requireSharedTransport: true,
            transportScope: TransportScope::PerConnectionDoctrine,
        );

        self::assertFalse($guard->isSatisfied());

        $this->expectException(AsyncRefreshRequiresSharedTransport::class);
        $this->expectExceptionMessage('per_connection_doctrine');

        $guard->ensureAsyncIsSupported();
    }

    #[DataProvider('toleratedTopologyProvider')]
    public function testToleratesAnyScopeWhenSharedTransportIsNotRequired(TransportScope $scope): void
    {
        $guard = new SharedTransportGuard(
            requireSharedTransport: false,
            transportScope: $scope,
        );

        self::assertTrue($guard->isSatisfied());

        $guard->ensureAsyncIsSupported();
    }

    /**
     * @return iterable<string, array{TransportScope}>
     */
    public static function toleratedTopologyProvider(): iterable
    {
        yield 'shared scope' => [TransportScope::Shared];
        yield 'per-connection doctrine scope' => [TransportScope::PerConnectionDoctrine];
    }
}
