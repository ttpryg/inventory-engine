<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Tests\Support;

use RuntimeException;
use Ttpryg\InventoryEngine\Contracts\StockMovementRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\StockMovement;

class FailingStockMovementRepository implements StockMovementRepositoryInterface
{
    public function record(StockMovement $movement): void
    {
        throw new RuntimeException('Simulated stock movement failure');
    }

    public function findByStock(string $inventoryStockId, int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function findByLocation(string $locationType, string $locationId, int $limit = 50, int $offset = 0): array
    {
        return [];
    }
}
