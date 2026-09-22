<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Repositories;

use Ttpryg\InventoryEngine\Contracts\StockMovementRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\StockMovement;

class MemoryStockMovementRepository implements StockMovementRepositoryInterface
{
    /** @var array<string, StockMovement> */
    private array $movements = [];

    public function record(StockMovement $movement): void
    {
        $this->movements[$movement->id] = $movement;
    }

    public function findByStock(string $inventoryStockId, int $limit = 50, int $offset = 0): array
    {
        $filtered = [];
        foreach ($this->movements as $m) {
            if ($m->inventoryStockId === $inventoryStockId) {
                $filtered[] = $m;
            }
        }
        return array_slice($filtered, $offset, $limit);
    }

    public function findByLocation(string $locationType, string $locationId, int $limit = 50, int $offset = 0): array
    {
        $filtered = [];
        foreach ($this->movements as $m) {
            if ($m->locationType === $locationType && $m->locationId === $locationId) {
                $filtered[] = $m;
            }
        }
        return array_slice($filtered, $offset, $limit);
    }
}
