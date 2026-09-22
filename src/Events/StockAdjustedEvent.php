<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Events;

use Ttpryg\InventoryEngine\Entities\InventoryStock;
use Ttpryg\InventoryEngine\Entities\StockMovement;

class StockAdjustedEvent
{
    public function __construct(
        public readonly InventoryStock $stock,
        public readonly StockMovement $movement
    ) {}
}
