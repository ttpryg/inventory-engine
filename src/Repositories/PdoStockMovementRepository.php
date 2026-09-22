<?php

namespace Ttpryg\InventoryEngine\Repositories;

use DateTimeImmutable;
use PDO;
use Ttpryg\InventoryEngine\Contracts\StockMovementRepositoryInterface;
use Ttpryg\InventoryEngine\Entities\StockMovement;
use Ttpryg\InventoryEngine\Enums\MovementType;

class PdoStockMovementRepository implements StockMovementRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function record(StockMovement $movement): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO stock_movements (id, inventory_stock_id, location_type, location_id, type, quantity, reference_type, reference_id, note, actor_id, created_at) VALUES (:id, :inventory_stock_id, :location_type, :location_id, :type, :quantity, :reference_type, :reference_id, :note, :actor_id, :created_at)');
        $stmt->execute([
            'id' => $movement->id,
            'inventory_stock_id' => $movement->inventoryStockId,
            'location_type' => $movement->locationType,
            'location_id' => $movement->locationId,
            'type' => $movement->type->value,
            'quantity' => $movement->quantity,
            'reference_type' => $movement->referenceType,
            'reference_id' => $movement->referenceId,
            'note' => $movement->note,
            'actor_id' => $movement->actorId,
            'created_at' => $movement->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByStock(string $inventoryStockId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_movements WHERE inventory_stock_id = :inventory_stock_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue('inventory_stock_id', $inventoryStockId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapToEntity($row);
        }

        return $result;
    }

    public function findByLocation(string $locationType, string $locationId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_movements WHERE location_type = :location_type AND location_id = :location_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue('location_type', $locationType);
        $stmt->bindValue('location_id', $locationId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapToEntity($row);
        }

        return $result;
    }

    private function mapToEntity(array $row): StockMovement
    {
        return new StockMovement(
            id: (string) $row['id'],
            inventoryStockId: (string) $row['inventory_stock_id'],
            locationType: (string) $row['location_type'],
            locationId: (string) $row['location_id'],
            type: MovementType::from($row['type']),
            quantity: (int) $row['quantity'],
            referenceType: $row['reference_type'] ?? null,
            referenceId: $row['reference_id'] ?? null,
            note: $row['note'] ?? null,
            actorId: $row['actor_id'] ?? null,
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null
        );
    }
}
