<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Contracts;

use Ttpryg\InventoryEngine\Entities\StockMovement;

interface StockMovementRepositoryInterface
{
    public function record(StockMovement $movement): void;

    public function findByStock(string $inventoryStockId, int $limit = 50, int $offset = 0): array;

    public function findByLocation(string $locationType, string $locationId, int $limit = 50, int $offset = 0): array;
}
