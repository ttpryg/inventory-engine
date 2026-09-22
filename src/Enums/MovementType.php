<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Enums;

enum MovementType: string
{
    case IN = 'in';
    case OUT = 'out';
    case RESERVE = 'reserve';
    case COMMIT = 'commit';
    case RELEASE = 'release';
    case TRANSFER = 'transfer';
    case ADJUSTMENT = 'adjustment';
}
