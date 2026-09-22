<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Enums;

enum ReservationStatus: string
{
    case ACTIVE = 'active';
    case COMMITTED = 'committed';
    case RELEASED = 'released';
    case EXPIRED = 'expired';
}
