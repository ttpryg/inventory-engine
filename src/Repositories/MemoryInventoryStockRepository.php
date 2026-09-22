<?php

namespace Ttpryg\InventoryEngine\Repositories;

use Ttpryg\InventoryEngine\Contracts\InventoryStockRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\InventoryStock;

class MemoryInventoryStockRepository implements InventoryStockRepositoryInterface
{
    /** @var array<string, InventoryStock> */
    private array $stocks = [];

    public function save(InventoryStock $stock): void
    {
        $this->stocks[$stock->id] = $stock;
    }

    public function findById(string $id): ?InventoryStock
    {
        return $this->stocks[$id] ?? null;
    }

    public function findByLocationAndProduct(string $locationType, string $locationId, string $productId, ?string $variantId = null): ?InventoryStock
    {
        foreach ($this->stocks as $stock) {
            if ($stock->locationType === $locationType && $stock->locationId === $locationId && $stock->productId === $productId) {
                if ($variantId === null || $stock->variantId === $variantId) {
                    return $stock;
                }
            }
        }
        return null;
    }

    public function findByProduct(string $productId, ?string $variantId = null): array
    {
        $result = [];
        foreach ($this->stocks as $stock) {
            if ($stock->productId === $productId) {
                if ($variantId === null || $stock->variantId === $variantId) {
                    $result[] = $stock;
                }
            }
        }
        return $result;
    }

    public function findLowStockItems(?string $locationType = null, ?string $locationId = null): array
    {
        $result = [];
        foreach ($this->stocks as $stock) {
            if ($locationType === null || ($stock->locationType === $locationType && $stock->locationId === $locationId)) {
                if ($stock->isLowStock()) {
                    $result[] = $stock;
                }
            }
        }
        return $result;
    }
}
