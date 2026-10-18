<?php

declare(strict_types=1);

namespace Ttpryg\InventoryEngine\Repositories;

use PDO;
use Ttpryg\InventoryEngine\Contracts\InventoryStockRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\InventoryStock;
use Ttpryg\InventoryEngine\Support\PdoRow;

class PdoInventoryStockRepository implements InventoryStockRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(InventoryStock $stock): void
    {
        $params = [
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
        ];

        if ($this->driverName() === 'sqlite') {
            $sql = 'INSERT INTO inventory_stocks (id, location_type, location_id, product_id, variant_id, sku, quantity_on_hand, quantity_reserved, min_stock_alert, created_at, updated_at) VALUES (:id, :location_type, :location_id, :product_id, :variant_id, :sku, :quantity_on_hand, :quantity_reserved, :min_stock_alert, :created_at, :updated_at) ON CONFLICT(id) DO UPDATE SET location_type = excluded.location_type, location_id = excluded.location_id, product_id = excluded.product_id, variant_id = excluded.variant_id, sku = excluded.sku, quantity_on_hand = excluded.quantity_on_hand, quantity_reserved = excluded.quantity_reserved, min_stock_alert = excluded.min_stock_alert, updated_at = excluded.updated_at';
        } else {
            $sql = 'INSERT INTO inventory_stocks (id, location_type, location_id, product_id, variant_id, sku, quantity_on_hand, quantity_reserved, min_stock_alert, created_at, updated_at) VALUES (:id, :location_type, :location_id, :product_id, :variant_id, :sku, :quantity_on_hand, :quantity_reserved, :min_stock_alert, :created_at, :updated_at) ON DUPLICATE KEY UPDATE location_type = VALUES(location_type), location_id = VALUES(location_id), product_id = VALUES(product_id), variant_id = VALUES(variant_id), sku = VALUES(sku), quantity_on_hand = VALUES(quantity_on_hand), quantity_reserved = VALUES(quantity_reserved), min_stock_alert = VALUES(min_stock_alert), updated_at = VALUES(updated_at)';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function findById(string $id): ?InventoryStock
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_stocks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByIdForUpdate(string $id): ?InventoryStock
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_stocks WHERE id = :id'.$this->lockClause());
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

    public function findByLocationAndProductForUpdate(string $locationType, string $locationId, string $productId, ?string $variantId = null): ?InventoryStock
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

        $stmt = $this->pdo->prepare($sql.$this->lockClause());
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

    private function driverName(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return is_string($driver) ? $driver : '';
    }

    private function lockClause(): string
    {
        return in_array($this->driverName(), ['mysql', 'mariadb'], true) ? ' FOR UPDATE' : '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapToEntity(array $row): InventoryStock
    {
        return new InventoryStock(
            id: PdoRow::string($row, 'id'),
            locationType: PdoRow::string($row, 'location_type'),
            locationId: PdoRow::string($row, 'location_id'),
            productId: PdoRow::string($row, 'product_id'),
            variantId: PdoRow::nullableString($row, 'variant_id'),
            sku: PdoRow::nullableString($row, 'sku'),
            quantityOnHand: PdoRow::int($row, 'quantity_on_hand'),
            quantityReserved: PdoRow::int($row, 'quantity_reserved'),
            minStockAlert: PdoRow::int($row, 'min_stock_alert', 5),
            createdAt: PdoRow::dateTime($row, 'created_at'),
            updatedAt: PdoRow::dateTime($row, 'updated_at')
        );
    }
}
