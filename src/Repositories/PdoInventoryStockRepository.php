<?php

namespace Ttpryg\InventoryEngine\Repositories;

use DateTimeImmutable;
use PDO;
use Ttpryg\InventoryEngine\Contracts\InventoryStockRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\InventoryStock;

class PdoInventoryStockRepository implements InventoryStockRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(InventoryStock $stock): void
    {
        $existing = $this->findById($stock->id);

        $sql = $existing instanceof \Ttpryg\InventoryEngine\Entities\InventoryStock
            ? 'UPDATE inventory_stocks SET location_type = :location_type, location_id = :location_id, product_id = :product_id, variant_id = :variant_id, sku = :sku, quantity_on_hand = :quantity_on_hand, quantity_reserved = :quantity_reserved, min_stock_alert = :min_stock_alert, updated_at = :updated_at WHERE id = :id'
            : 'INSERT INTO inventory_stocks (id, location_type, location_id, product_id, variant_id, sku, quantity_on_hand, quantity_reserved, min_stock_alert, created_at, updated_at) VALUES (:id, :location_type, :location_id, :product_id, :variant_id, :sku, :quantity_on_hand, :quantity_reserved, :min_stock_alert, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $stock->id,
            'location_type' => $stock->locationType,
            'location_id' => $stock->locationId,
            'product_id' => $stock->productId,
            'variant_id' => $stock->variantId,
            'sku' => $stock->sku,
            'quantity_on_hand' => $stock->quantityOnHand,
            'quantity_reserved' => $stock->quantityReserved,
            'min_stock_alert' => $stock->minStockAlert,
            'created_at' => $stock->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $stock->updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?InventoryStock
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_stocks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByLocationAndProduct(string $locationType, string $locationId, string $productId, ?string $variantId = null): ?InventoryStock
    {
        $sql = 'SELECT * FROM inventory_stocks WHERE location_type = :location_type AND location_id = :location_id AND product_id = :product_id';
        $params = [
            'location_type' => $locationType,
            'location_id' => $locationId,
            'product_id' => $productId,
        ];

        if ($variantId !== null) {
            $sql .= ' AND variant_id = :variant_id';
            $params['variant_id'] = $variantId;
        } else {
            $sql .= ' AND variant_id IS NULL';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByProduct(string $productId, ?string $variantId = null): array
    {
        $sql = 'SELECT * FROM inventory_stocks WHERE product_id = :product_id';
        $params = ['product_id' => $productId];

        if ($variantId !== null) {
            $sql .= ' AND variant_id = :variant_id';
            $params['variant_id'] = $variantId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapToEntity($row);
        }

        return $result;
    }

    public function findLowStockItems(?string $locationType = null, ?string $locationId = null): array
    {
        $sql = 'SELECT * FROM inventory_stocks WHERE (quantity_on_hand - quantity_reserved) <= min_stock_alert';
        $params = [];

        if ($locationType !== null && $locationId !== null) {
            $sql .= ' AND location_type = :location_type AND location_id = :location_id';
            $params['location_type'] = $locationType;
            $params['location_id'] = $locationId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapToEntity($row);
        }

        return $result;
    }

    private function mapToEntity(array $row): InventoryStock
    {
        return new InventoryStock(
            id: (string) $row['id'],
            locationType: (string) $row['location_type'],
            locationId: (string) $row['location_id'],
            productId: (string) $row['product_id'],
            variantId: $row['variant_id'] ?? null,
            sku: $row['sku'] ?? null,
            quantityOnHand: (int) $row['quantity_on_hand'],
            quantityReserved: (int) $row['quantity_reserved'],
            minStockAlert: (int) ($row['min_stock_alert'] ?? 5),
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
            updatedAt: ! empty($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null
        );
    }
}
