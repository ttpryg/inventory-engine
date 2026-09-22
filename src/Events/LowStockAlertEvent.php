<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Events;

use Ttpryg\InventoryEngine\Entities\InventoryStock;

class LowStockAlertEvent
{
    public function __construct(public readonly InventoryStock $stock) {}
}
