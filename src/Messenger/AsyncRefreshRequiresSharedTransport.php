<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Messenger;

use RuntimeException;
use Th3Mouk\MaterializedViewBundle\Exception\MaterializedViewBundleError;

final class AsyncRefreshRequiresSharedTransport extends RuntimeException implements MaterializedViewBundleError
{
    public static function forScope(TransportScope $scope): self
    {
        return new self(\sprintf(
            'PopulationPolicy::Async requires a shared message transport (AMQP, Redis, SQS, or a single technical database), but the configured transport scope is "%s". A per-connection Doctrine transport stores the message in each database, so a standard worker cannot drain the fleet. Configure a shared transport, or set async.require_shared_transport to false to accept the risk, or prefer PopulationPolicy::Manual with a scheduled refresh.',
            $scope->value,
        ));
    }
}
