<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Contracts;

use Ttpryg\InventoryEngine\Entities\InventoryStock;

interface InventoryStockRepositoryInterface
{
    public function save(InventoryStock $stock): void;

    public function findById(string $id): ?InventoryStock;

    public function findByLocationAndProduct(string $locationType, string $locationId, string $productId, ?string $variantId = null): ?InventoryStock;

    public function findByProduct(string $productId, ?string $variantId = null): array;

    public function findLowStockItems(?string $locationType = null, ?string $locationId = null): array;
}
