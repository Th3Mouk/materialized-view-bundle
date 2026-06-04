<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Messenger;

enum TransportScope: string
{
    case Shared = 'shared';
    case PerConnectionDoctrine = 'per_connection_doctrine';

    public function isShared(): bool
    {
        return self::Shared === $this;
    }
}
