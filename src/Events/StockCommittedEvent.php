<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Events;

use Ttpryg\InventoryEngine\Entities\StockReservation;

class StockCommittedEvent
{
    public function __construct(public readonly StockReservation $reservation) {}
}
